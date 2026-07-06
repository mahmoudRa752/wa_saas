-- =============================================================================
-- Migration: 0002_tenancy_core.sql
-- Purpose  : Creates the core tenancy model for the platform, per the frozen
--            "Enterprise Omnichannel Customer Communication Platform"
--            architecture (Workspace-first, not Company-first):
--              - workspaces          (the tenant root -- Individual/Team/Company/Organization)
--              - workspace_settings  (1:1 config bag per workspace; keeps `workspaces` lean)
--              - users               (global identity; NOT scoped to one workspace)
--              - workspace_members   (bridge: which users belong to which workspaces)
--
-- Product/architecture context (for future readers):
--   This is no longer a "WhatsApp SaaS for companies" schema. The platform is an
--   Enterprise Omnichannel Communication Platform that launches with WhatsApp as
--   its first channel. The tenant root is a Workspace, which may represent an
--   Individual, a Team, a Company, or an Organization -- the exact same tables
--   and code path serve all four; `workspace_types` (0001) only carries a label
--   and optional soft defaults, it never forks structure or logic.
--
--   A user is never tied to exactly one workspace. `users` is a pure global
--   identity table (email/password/profile); `workspace_members` is the bridge
--   that says "this user belongs to this workspace" (one user may belong to
--   many workspaces -- e.g. an agency consultant working inside several client
--   workspaces with one login). Role assignment (Owner/Admin/Manager/Agent) is
--   NOT stored here -- it lives on the membership via `workspace_member_roles`,
--   created in 0003_identity_roles.sql, once `roles` exists. There is
--   deliberately no `owner_user_id` column on `workspaces`: ownership is
--   represented entirely through RBAC (a user holding the system "Owner" role
--   within workspace_members for that workspace), so there is exactly one
--   source of truth for "who can do what in this workspace." See
--   architecture_review_membership_and_types.md for the full design review.
--
-- Depends on : 0001_lookup_catalogs.sql (workspace_types, workspace_statuses,
--              workspace_member_statuses, plans must already exist for the
--              FKs below).
--
-- Revision note (this pass, per Sprint 1 Product Backlog approval):
--   - workspace_members: replaced the boolean `is_active` with a proper
--     `status_id` FK -> workspace_member_statuses (0001), and added
--     `last_active_at`, per approved membership-lifecycle refinement.
--   - workspace_settings: added `allow_private_mode`, the workspace-level
--     governance switch that gates whether workspace_members.privacy_mode may
--     be set to 'private' at all -- privacy is a member-declared state gated
--     by workspace policy, not an RBAC permission (see inline comment above
--     workspace_members below for the full reasoning).
--   - users.email uniqueness is confirmed GLOBAL (uq_users_email below), per
--     Sprint 1 Product Backlog resolved decision -- a deliberate behavior
--     change from the legacy per-company model, not an oversight.
--   - New workspaces are expected to default to the 'pending' row in
--     workspace_statuses (0001) at creation time; that default is applied by
--     the application/service layer in Sprint 2 (this DDL only requires
--     status_id to be NOT NULL -- it does not hardcode a DEFAULT here, since
--     the "pending" row's id is a seeded value from 0021, not known at DDL
--     time).
--
-- Touches existing tables? No. This migration ONLY adds new tables. It does
-- not alter, rename, or drop `whatsapp_numbers`, `chat_messages`, or any other
-- existing table. The live app continues to run unmodified against the old
-- schema until the Sprint 2 PHP refactor begins consuming these new tables.
--
-- Backward compatibility (answered per project standing rule):
--   1. Will this break the current app?      No -- `workspaces`, `users`,
--      `workspace_members`, `workspace_settings` are brand-new tables not yet
--      referenced by any existing PHP code. The app's real user table today is
--      whatever `whatsapp_numbers.user_id` / `chat_messages.user_id` point at
--      (a legacy, un-migrated users table) -- that legacy table is left
--      completely untouched by this file. Reconciling legacy data into this
--      new `users`/`workspaces` model is a deliberate, separate step (planned
--      as 0022_legacy_data_migration.sql, DML-only, transactional, per
--      standard #10) -- NOT part of this DDL file.
--   2. Will existing PHP keep working?        Yes, unchanged. Nothing here is
--      read or written by any current PHP file.
--   3. Is data migration required?            Not in this file. The legacy
--      "Legacy Company" -> "Legacy Workspace" backfill (earliest-registered
--      user becomes the Workspace's Owner via workspace_member_roles, all
--      other existing users become Members) happens in the dedicated
--      0022_legacy_data_migration.sql DML migration, after 0003 (roles) and
--      0005 (channel accounts) exist, so the backfill can wire up roles and
--      channel ownership in one consistent transactional pass.
--   4. Rollback/recovery if something fails?  No data exists yet in any table
--      created here, and nothing outside this file references them yet (0003
--      is the first dependent). Rollback = `DROP TABLE IF EXISTS
--      workspace_members, workspace_settings, users, workspaces;` (child-first
--      order) then re-apply the corrected file. Once 0003+ add FKs into
--      `users`/`workspace_members`, this simple rollback stops being viable on
--      its own -- dependents must be dropped first, which is exactly why the
--      tenancy model was frozen (per user approval) before generating this file.
--
-- Idempotency / transaction note:
--   Same approach as 0001: DDL cannot be wrapped in a real transaction in
--   MySQL/InnoDB (implicit commit per statement). Safety here comes from
--   `CREATE TABLE IF NOT EXISTS` on every table plus FK-safe ordering
--   (workspaces -> workspace_settings/users -> workspace_members), so this
--   file can be re-run after fixing any error without manual cleanup.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- workspaces: the tenant root of the platform. May represent an Individual,
-- Team, Company, or Organization (see workspace_types, 0001) -- the same table
-- and the same downstream schema (conversations, messages, channel_accounts,
-- etc.) serve all four with zero structural forking. Lifecycle state lives in
-- `workspace_statuses` (0001), not an ENUM, so future billing/subscription
-- logic (0014_billing.sql) can attach behavior to a status without a schema
-- change. Deliberately has NO `owner_user_id` column -- see file header above
-- for why ownership is represented entirely via RBAC in workspace_member_roles
-- (0003), not duplicated here.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workspaces (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36) NOT NULL,                 -- public identifier for APIs/URLs; never expose internal id
    name            VARCHAR(150) NOT NULL,
    slug            VARCHAR(150) NULL,                 -- optional URL-friendly handle, e.g. for future workspace subdomains
    type_id         BIGINT UNSIGNED NOT NULL,           -- FK -> workspace_types (0001): individual/team/company/organization
    status_id       BIGINT UNSIGNED NOT NULL,           -- FK -> workspace_statuses (0001): active/trial/pending/suspended/archived
    plan_id         BIGINT UNSIGNED NULL,                -- FK -> plans (0001); nullable until a plan is assigned/onboarding completes
    timezone        VARCHAR(64) NOT NULL DEFAULT 'UTC',  -- IANA tz name; all stored timestamps remain UTC regardless (standard #9)
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()), -- NOTE: no automatic ON UPDATE clause: MySQL/MariaDB only permit CURRENT_TIMESTAMP for that, never an arbitrary function. Standard #7 forbids CURRENT_TIMESTAMP (server-local-timezone-dependent). The Service layer MUST explicitly set updated_at = UTC_TIMESTAMP() on every UPDATE statement (permanent engineering rule).
    deleted_at      DATETIME NULL,                      -- soft-delete: a workspace can be deactivated/closed without losing history
    CONSTRAINT uq_workspaces_uuid UNIQUE (uuid),
    CONSTRAINT uq_workspaces_slug UNIQUE (slug),
    KEY idx_workspaces_type_id (type_id),
    KEY idx_workspaces_status_id (status_id),
    KEY idx_workspaces_plan_id (plan_id),
    KEY idx_workspaces_deleted_at (deleted_at),
    CONSTRAINT fk_workspaces_type FOREIGN KEY (type_id) REFERENCES workspace_types(id),
    CONSTRAINT fk_workspaces_status FOREIGN KEY (status_id) REFERENCES workspace_statuses(id),
    CONSTRAINT fk_workspaces_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tenant root of the platform. A Workspace may represent an Individual, Team, Company, or Organization (see workspace_types); the same schema serves all four. Ownership is represented via RBAC in workspace_member_roles (0003), not a column here.';

-- -----------------------------------------------------------------------------
-- workspace_settings: 1:1 configuration bag per workspace. Kept as a separate
-- table (not columns bolted onto `workspaces`) so workspace-level settings can
-- grow over time (e.g. business hours, auto-reply defaults, branding) without
-- repeatedly widening the hot `workspaces` table itself.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workspace_settings (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id            BIGINT UNSIGNED NOT NULL,
    default_reply_language  VARCHAR(10) NULL,           -- e.g. 'en', 'ar'; used for future AI-agent/auto-reply defaults
    business_hours_json     JSON NULL,                  -- flexible schedule config; not queried directly, so JSON is appropriate here
    branding_json           JSON NULL,                  -- logo/colors for future customer-facing widgets
    allow_private_mode      TINYINT(1) NOT NULL DEFAULT 1, -- workspace-level GOVERNANCE switch: may members opt their own membership into Private visibility at all? Distinct from workspace_members.privacy_mode below, which is the per-member STATE. A compliance-sensitive workspace can set this to 0 to force full admin visibility for every member, regardless of individual privacy_mode values (enforced at the application layer).
    created_at              DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at              DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()), -- NOTE: no automatic ON UPDATE clause: MySQL/MariaDB only permit CURRENT_TIMESTAMP for that, never an arbitrary function. Standard #7 forbids CURRENT_TIMESTAMP (server-local-timezone-dependent). The Service layer MUST explicitly set updated_at = UTC_TIMESTAMP() on every UPDATE statement (permanent engineering rule).
    CONSTRAINT uq_workspace_settings_workspace_id UNIQUE (workspace_id),
    CONSTRAINT fk_workspace_settings_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One-to-one configuration bag per workspace (business hours, branding, reply-language defaults, privacy governance). Kept separate from workspaces to keep that hot table lean as settings grow.';

-- -----------------------------------------------------------------------------
-- users: global person identity. NEVER scoped to a single workspace and NEVER
-- forked into separate "Employee"/"Admin" entities -- Owner, Admin, Manager,
-- and Agent are all simply Users holding different Roles within a given
-- workspace (see workspace_member_roles, 0003). A user may belong to zero,
-- one, or many workspaces via `workspace_members` below (e.g. a consultant
-- with one login working across several client workspaces).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36) NOT NULL,             -- public identifier for APIs/URLs; never expose internal id
    email               VARCHAR(190) NOT NULL,          -- 190 leaves room under utf8mb4's 191-char unique-index limit on older MySQL/InnoDB (row format dependent)
    password_hash       VARCHAR(255) NOT NULL,
    full_name           VARCHAR(150) NOT NULL,
    phone                VARCHAR(30) NULL,
    avatar_url          VARCHAR(500) NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    email_verified_at   DATETIME NULL,
    last_login_at       DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()), -- NOTE: no automatic ON UPDATE clause: MySQL/MariaDB only permit CURRENT_TIMESTAMP for that, never an arbitrary function. Standard #7 forbids CURRENT_TIMESTAMP (server-local-timezone-dependent). The Service layer MUST explicitly set updated_at = UTC_TIMESTAMP() on every UPDATE statement (permanent engineering rule).
    deleted_at          DATETIME NULL,                  -- soft-delete: preserves FK history in messages/audit trails after account closure
    CONSTRAINT uq_users_uuid UNIQUE (uuid),
    CONSTRAINT uq_users_email UNIQUE (email),
    KEY idx_users_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Global person identity. Every authenticated person (Owner, Admin, Manager, Agent) is a User; role is assigned per workspace via workspace_member_roles (0003), never as a separate entity type.';

-- -----------------------------------------------------------------------------
-- workspace_members: bridge table between users and workspaces. A row here
-- means "this user belongs to this workspace"; it carries membership-level
-- metadata only (invitation trail, privacy mode). Role/permission assignment
-- is intentionally NOT on this table -- it lives in workspace_member_roles
-- (0003), so one membership can hold zero-to-many roles cleanly without
-- widening this table every time RBAC evolves.
--
-- `privacy_mode` carries forward the existing product concept of per-member
-- Shared vs Private conversation visibility (Shared = workspace admins can
-- read the member's conversations; Private = admins see aggregate stats only).
-- It is scoped to the membership (not the user) because the same person's
-- privacy choice can reasonably differ between workspaces they belong to.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workspace_members (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id    BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    privacy_mode    ENUM('shared','private') NOT NULL DEFAULT 'shared',
    invited_by_user_id BIGINT UNSIGNED NULL,            -- who invited this member; NULL for the first/self-registered member of a workspace
    invited_at      DATETIME NULL,
    joined_at       DATETIME NULL,                       -- NULL while an invite is pending acceptance
    status_id       BIGINT UNSIGNED NOT NULL,             -- FK -> workspace_member_statuses (0001): active/suspended; replaces a plain boolean so future states (e.g. "on_leave") don't require a schema change
    last_active_at  DATETIME NULL,                        -- last time this member was seen active in this workspace; used for engagement/audit views, not for authorization
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()), -- NOTE: no automatic ON UPDATE clause: MySQL/MariaDB only permit CURRENT_TIMESTAMP for that, never an arbitrary function. Standard #7 forbids CURRENT_TIMESTAMP (server-local-timezone-dependent). The Service layer MUST explicitly set updated_at = UTC_TIMESTAMP() on every UPDATE statement (permanent engineering rule).
    deleted_at      DATETIME NULL,                        -- soft-delete: member removed from workspace, but referenced messages/conversations keep their history intact
    CONSTRAINT uq_workspace_members_workspace_user UNIQUE (workspace_id, user_id),
    KEY idx_workspace_members_user_id (user_id),
    KEY idx_workspace_members_status_id (status_id),
    KEY idx_workspace_members_deleted_at (deleted_at),
    CONSTRAINT fk_workspace_members_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_workspace_members_invited_by FOREIGN KEY (invited_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_workspace_members_status FOREIGN KEY (status_id) REFERENCES workspace_member_statuses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bridge table: which users belong to which workspaces, plus membership-level metadata (invite trail, privacy mode, lifecycle status). Role/permission assignment lives separately in workspace_member_roles (0003) so one membership can hold multiple roles.';
