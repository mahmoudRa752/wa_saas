-- =============================================================================
-- Migration: 0003_identity_roles.sql
-- Purpose  : Adds workspace-scoped RBAC on top of the tenancy core (0002):
--              - roles                  (system defaults + per-workspace custom roles)
--              - role_permissions       (bridge: which permissions a role grants)
--              - workspace_member_roles (bridge: which roles a membership holds)
--
-- Scope note: per explicit instruction, this file implements ONLY the above
-- three tables plus `roles`. It does NOT implement authentication (login/
-- sessions/password reset -- Epic G, deferred) and does NOT implement
-- `workspace_invitations` (Epic E -- deferred to a later Sprint 1 file). It
-- does NOT modify any table from 0000/0001/0002 or any legacy table.
--
-- Product/architecture context (for future readers):
--   `permissions` already exists (0001) as a lookup catalog seeded independently
--   of roles, so it could be referenced the moment `roles` exists -- this file
--   is what finally connects the two. Role assignment is NOT stored on `users`
--   or on `workspace_members` directly: a user's role is a property of their
--   MEMBERSHIP in a specific workspace (workspace_member_roles references
--   workspace_members.id, not users.id), because the same person can hold
--   different roles in different workspaces they belong to (e.g. Owner of
--   their own workspace, Agent inside a client's workspace they were invited
--   into). A membership may hold more than one role (many-to-many), so a
--   future "custom role bundles" feature (e.g. Manager + Billing-Admin) needs
--   no schema change.
--
--   `roles` supports both system default roles (Owner/Admin/Manager/Agent,
--   workspace_id IS NULL, is_system = 1) and future per-workspace custom
--   roles (workspace_id set, is_system = 0), mirroring the same
--   system-vs-custom pattern already used for `workspace_types`/
--   `workspace_statuses` (0001) -- a lookup-style table, not an ENUM, because
--   custom roles must be definable by a workspace at runtime without a schema
--   change. System roles are seeded in 0021 (seed data), not in this DDL file,
--   per standard #8 (seed/reference data lives in dedicated seed migrations).
--
-- Depends on : 0002_tenancy_core.sql (workspaces, workspace_members) and
--              0001_lookup_catalogs.sql (permissions) must already exist for
--              the FKs below.
--
-- Touches existing tables? No. This migration ONLY adds new tables. It does
-- not alter, rename, or drop `whatsapp_numbers`, `chat_messages`, `workspaces`,
-- `workspace_settings`, `users`, `workspace_members`, or any other existing
-- table. The live app continues to run unmodified against the old schema.
--
-- Strangler Fig note: the legacy PHP app has no concept of roles/permissions
-- today (every logged-in user is implicitly an admin of their own
-- `whatsapp_numbers` rows). This file does not touch or replace that
-- behavior -- it only adds the new RBAC tables that Sprint 2 PHP work will
-- gradually consume, one endpoint at a time, alongside the legacy behavior
-- until each endpoint is migrated. No Big Bang cutover.
--
-- Pre-implementation review (per project standing rule):
--   1. Will this affect the current login flow?        No. Legacy login is
--      unaffected; nothing here is read by any existing PHP file.
--   2. Will this affect existing WhatsApp functionality? No. `whatsapp_numbers`
--      and `chat_messages` are not referenced by this file at all.
--   3. Will any existing PHP file stop working?          No. Zero existing
--      PHP file queries `roles`, `role_permissions`, or `workspace_member_roles`.
--   4. Does this duplicate a legacy concept?              No. The legacy schema
--      has no roles/permissions concept to duplicate -- this is purely
--      additive. `permissions` (0001) is extended, not re-implemented.
--   5. Is there a simpler solution?                       A single ENUM role
--      column directly on `workspace_members` was considered and rejected
--      during the frozen tenancy review: it cannot express multiple roles per
--      membership nor per-workspace custom roles without a schema change,
--      both of which the approved architecture requires.
--
-- Backward compatibility (answered per project standing rule):
--   1. Will this break the current app?      No -- all three tables are brand
--      new and not yet referenced by any existing PHP code.
--   2. Will existing PHP keep working?        Yes, unchanged.
--   3. Is data migration required?            Not in this file. Backfilling
--      legacy users into workspace_member_roles (e.g. earliest-registered
--      user per legacy company becomes Owner) happens in the dedicated
--      0022_legacy_data_migration.sql DML migration, once workspace_invitations
--      and channel_accounts also exist, so the whole legacy backfill can run
--      as one consistent transactional pass.
--   4. Rollback/recovery if something fails?  No data exists yet in any table
--      created here, and nothing outside this file references them yet.
--      Rollback = `DROP TABLE IF EXISTS workspace_member_roles, role_permissions,
--      roles;` (child-first order) then re-apply the corrected file.
--
-- Idempotency / transaction note:
--   Same approach as 0001/0002: DDL cannot be wrapped in a real transaction in
--   MySQL/InnoDB (implicit commit per statement). Safety here comes from
--   `CREATE TABLE IF NOT EXISTS` on every table plus FK-safe ordering
--   (roles -> role_permissions/workspace_member_roles), so this file can be
--   re-run after fixing any error without manual cleanup.
--
-- Validation performed: applied 0000 -> 0001 -> 0002 -> 0003 on a clean,
-- throwaway MariaDB 10.11 instance; re-ran all four again to confirm a clean
-- idempotent no-op; verified every foreign key resolves and every named index
-- exists (see validation log referenced in the delivery message).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- roles: workspace-scoped RBAC role catalog. `workspace_id IS NULL` marks a
-- system default role (Owner, Admin, Manager, Agent) available to every
-- workspace; `workspace_id` set marks a custom role defined by that specific
-- workspace. `is_system` additionally guards system rows against accidental
-- deletion/rename by workspace-level UI (enforced at the application layer;
-- MySQL has no partial/conditional constraint for "protect rows where X").
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id    BIGINT UNSIGNED NULL,               -- NULL = system default role shared by all workspaces; set = workspace-custom role
    name            VARCHAR(100) NOT NULL,              -- display name, e.g. 'Owner', 'Admin', 'Manager', 'Agent'
    slug            VARCHAR(100) NOT NULL,              -- machine-readable key, e.g. 'owner', 'admin', 'manager', 'agent'
    description     VARCHAR(255) NULL,
    is_system       TINYINT(1) NOT NULL DEFAULT 0,      -- 1 = platform-defined role, protected from deletion/rename in the app layer
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()), -- NOTE: no automatic ON UPDATE clause: MySQL/MariaDB only permit CURRENT_TIMESTAMP for that, never an arbitrary function. Standard #7 forbids CURRENT_TIMESTAMP (server-local-timezone-dependent). The Service layer MUST explicitly set updated_at = UTC_TIMESTAMP() on every UPDATE statement (permanent engineering rule).
    deleted_at      DATETIME NULL,                       -- soft-delete: a custom role can be retired without breaking historical workspace_member_roles rows
    CONSTRAINT uq_roles_workspace_slug UNIQUE (workspace_id, slug), -- slug unique per workspace; system rows (workspace_id NULL) unique among themselves too
    KEY idx_roles_workspace_id (workspace_id),
    KEY idx_roles_deleted_at (deleted_at),
    CONSTRAINT fk_roles_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Workspace-scoped RBAC role catalog. workspace_id NULL = system default role (Owner/Admin/Manager/Agent) available to every workspace; workspace_id set = a workspace-defined custom role. Permissions are attached via role_permissions; assignment to a membership via workspace_member_roles.';

-- -----------------------------------------------------------------------------
-- role_permissions: bridge table -- which permissions (0001) a role grants.
-- Composite PK (role_id, permission_id) both enforces uniqueness and serves as
-- the lookup index for "all permissions for this role", the table's primary
-- read pattern; a secondary index covers the reverse lookup.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id         BIGINT UNSIGNED NOT NULL,
    permission_id   BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_permission_id (permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bridge table: which fine-grained permissions (permissions, 0001) a role (roles) grants. Pure bridge table -- exempt from the dual BIGINT+UUID standard.';

-- -----------------------------------------------------------------------------
-- workspace_member_roles: bridge table -- which role(s) a specific membership
-- (workspace_members, 0002) holds. Deliberately references workspace_members.id
-- rather than users.id directly: role is a property of "this person's
-- participation in this workspace", not of the person globally, so the same
-- user can hold different roles across different workspace memberships (e.g.
-- Owner of their own workspace, Agent inside a client workspace they were
-- invited into) with zero ambiguity about which workspace a given role
-- assignment applies to.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workspace_member_roles (
    workspace_member_id BIGINT UNSIGNED NOT NULL,
    role_id             BIGINT UNSIGNED NOT NULL,
    assigned_by_user_id BIGINT UNSIGNED NULL,           -- who granted this role; NULL for system-assigned (e.g. auto-Owner on workspace creation)
    assigned_at         DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    PRIMARY KEY (workspace_member_id, role_id),
    KEY idx_workspace_member_roles_role_id (role_id),
    KEY idx_workspace_member_roles_assigned_by (assigned_by_user_id),
    CONSTRAINT fk_wmr_workspace_member FOREIGN KEY (workspace_member_id) REFERENCES workspace_members(id) ON DELETE CASCADE,
    CONSTRAINT fk_wmr_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_wmr_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bridge table: which role(s) a workspace membership (workspace_members, 0002) holds. Role is scoped to the membership, not the user, so one person can hold different roles across different workspaces. Pure bridge table -- exempt from the dual BIGINT+UUID standard.';
