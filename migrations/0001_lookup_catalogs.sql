-- =============================================================================
-- Migration: 0001_lookup_catalogs.sql
-- Purpose  : Creates the static, workspace-independent lookup/catalog tables
--            that everything else in the omnichannel schema will reference:
--              - channels         (WhatsApp, Telegram, Instagram, Messenger, Email, ...)
--              - providers        (Meta Cloud API, Evolution API, WAHA, 360Dialog, Twilio, ...)
--              - plans            (billing tiers; workspace_id relationship added in 0002/0014)
--              - permissions      (fine-grained RBAC permission slugs)
--              - features         (feature-flag catalog; plan/workspace entitlement tables come in 0014)
--              - workspace_types  (Individual, Team, Company, Organization -- 0002 tenancy pivot)
--              - workspace_statuses (Active, Trial, Pending, Suspended, Archived -- 0002 tenancy pivot)
--
-- Revision history:
--   v1 (initial) : first draft, pushed for review.
--   v2            : brought into compliance with the project-wide DB standards
--                  agreed after v1 was reviewed. Changes vs v1:
--                    - channels/providers PK switched TINYINT UNSIGNED -> BIGINT
--                      UNSIGNED, matching standard #8 uniformly across catalogs
--                      that will be joined by later FK'd tables.
--                    - Added table-level COMMENT to every table (standard #6).
--                    - Added updated_at to every table (standard #7); no
--                      deleted_at (see inline note on `channels` -- catalogs use
--                      is_active for deactivation, not soft-delete).
--                    - created_at/updated_at now default to UTC_TIMESTAMP()
--                      instead of CURRENT_TIMESTAMP, so values are UTC
--                      regardless of MySQL server/session timezone (standard #10).
--                    - Every UNIQUE key now has an explicit, descriptive name
--                      (standard #4) instead of relying on MySQL's
--                      auto-generated constraint name.
--                  No data existed under v1 (this table was never seeded or
--                  referenced by app code), so this is a safe in-place revision,
--                  not a breaking change. See backward-compatibility note below.
--   v3 (this)    : product-vision pivot from "WhatsApp SaaS for companies" to
--                  "Enterprise Omnichannel Platform" where the tenant root is a
--                  Workspace (Individual/Team/Company/Organization), not
--                  necessarily a Company. Changes vs v2:
--                    - Added `workspace_types` lookup table (replaces a plain
--                      ENUM on workspaces.type so each type can later carry
--                      metadata, e.g. default_max_users, without a schema
--                      change -- same rationale as every other catalog table
--                      in this file).
--                    - Added `workspace_statuses` lookup table (Active, Trial,
--                      Pending, Suspended, Archived) so lifecycle state is
--                      data, not an ENUM, and integrates naturally with future
--                      billing/subscription logic (0014_billing.sql).
--                    - Doc comments referencing "company-independent" /
--                      "company_id" updated to "workspace-independent" /
--                      "workspace_id" to match the frozen tenancy model; no
--                      column or table name in this file actually changes.
--                  Both new tables are additive only; nothing in v1/v2 of this
--                  file is altered structurally, so this remains a safe,
--                  non-breaking revision. See backward-compatibility note below.
--
-- Depends on : nothing (first schema-creating migration; 0000_schema_versions.sql
--              precedes this file once approved, but has no structural dependency
--              on it).
--
-- Touches existing tables? No. This migration ONLY adds new tables. It does not
-- alter, rename, or drop `whatsapp_numbers`, `chat_messages`, or any other
-- existing table. The app continues to run unmodified against the old schema
-- until the Sprint 2 PHP refactor.
--
-- Backward compatibility (answered per project standing rule):
--   1. Will this break the current app?      No -- these tables are not yet
--      referenced by any existing PHP code.
--   2. Will existing PHP keep working?        Yes, unchanged.
--   3. Is data migration required?            No -- tables are still empty;
--      seeding happens in 0021_seed_catalogs.sql.
--   4. Rollback/recovery if something fails?  Since no data or downstream FKs
--      exist yet, rollback = `DROP TABLE IF EXISTS providers, channels, plans,
--      permissions, features, workspace_types, workspace_statuses;` then
--      re-apply the corrected file. This simple rollback stops being viable
--      once 0002+ add real FKs pointing at `plans` / `workspace_types` /
--      `workspace_statuses` -- from that point on, rollback requires dropping
--      dependents first.
--
-- Idempotency / transaction note:
--   MySQL/InnoDB DDL statements (CREATE TABLE, ALTER TABLE) each cause an
--   implicit commit and CANNOT be rolled back by START TRANSACTION / ROLLBACK.
--   This file is therefore NOT wrapped in a transaction block -- doing so would
--   be misleading, since a failure partway through would still leave earlier
--   CREATE TABLE statements committed. Instead, safety is achieved via:
--     1. `CREATE TABLE IF NOT EXISTS` on every table, so this file can be
--        re-run after fixing any error without manual cleanup.
--     2. Statements ordered so every FK target exists before it is referenced
--        (channels before providers).
--   Files that contain only DML (seed data in 0021, legacy data copy in 0022)
--   WILL be wrapped in a real transaction with rollback on failure, since plain
--   INSERT/UPDATE/DELETE statements are fully transactional in InnoDB.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- channels: static catalog of communication channel types. Seeded (not here --
-- see 0021_seed_catalogs.sql) with: whatsapp, telegram, instagram, messenger, email.
-- Never company-scoped -- adding a new channel type is one INSERT, not a schema change.
-- No deleted_at: deactivation is done via is_active, since hard/soft-deleting a
-- channel type would orphan the meaning of historical channel_accounts/messages
-- rows that reference it.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS channels (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30) NOT NULL,              -- 'whatsapp', 'telegram', 'instagram', 'messenger', 'email'
    name            VARCHAR(100) NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) ON UPDATE UTC_TIMESTAMP(),
    CONSTRAINT uq_channels_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Static catalog of supported communication channel types (WhatsApp, Telegram, Instagram, Messenger, Email, ...). Company-independent; referenced by providers, channel_accounts, conversations, messages.';

-- -----------------------------------------------------------------------------
-- providers: static catalog of vendor/API integrations that actually deliver a
-- channel (e.g. WhatsApp can be served by meta_cloud_api, evolution_api, waha,
-- 360dialog, or twilio). Seeded in 0021 alongside `channels`.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS providers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(40) NOT NULL,              -- 'meta_cloud_api', 'evolution_api', 'waha', '360dialog', 'twilio'
    name                VARCHAR(100) NOT NULL,
    supports_channel_id BIGINT UNSIGNED NULL,               -- primary channel this provider serves (nullable; some providers, e.g. future email SMTP relays, may serve none directly)
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) ON UPDATE UTC_TIMESTAMP(),
    CONSTRAINT uq_providers_code UNIQUE (code),
    KEY idx_providers_supports_channel_id (supports_channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Static catalog of vendor/API integrations that deliver a channel (Meta Cloud API, Evolution API, WAHA, 360Dialog, Twilio, ...). One channel may have multiple providers.';

-- Added as a separate ALTER (matches the frozen architecture doc) so `providers`
-- can be created and re-run independently of the FK if ever needed; `channels`
-- already exists above in this same file so the FK target is guaranteed present.
-- MySQL has no "ADD CONSTRAINT IF NOT EXISTS"; guarded via information_schema so
-- this file stays safely re-runnable.
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'providers'
      AND CONSTRAINT_NAME = 'fk_providers_channel'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE providers ADD CONSTRAINT fk_providers_channel FOREIGN KEY (supports_channel_id) REFERENCES channels(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- plans: billing tiers (free/starter/growth/enterprise). Created here (before
-- `companies` in 0002) so that `companies.plan_id` can reference it directly
-- without the deferred-FK workaround the architecture doc describes -- since
-- migration order already guarantees `plans` exists first, no chicken-and-egg
-- problem exists in this migration sequence.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                    VARCHAR(60) NOT NULL,              -- 'free', 'starter', 'growth', 'enterprise'
    name                    VARCHAR(100) NOT NULL,
    max_channel_accounts    INT UNSIGNED NOT NULL DEFAULT 1,
    max_users               INT UNSIGNED NOT NULL DEFAULT 5,
    max_ai_agents           INT UNSIGNED NOT NULL DEFAULT 0,
    monthly_price_cents     INT UNSIGNED NOT NULL DEFAULT 0,
    yearly_price_cents      INT UNSIGNED NOT NULL DEFAULT 0,
    currency                CHAR(3) NOT NULL DEFAULT 'USD',
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    created_at              DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at              DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) ON UPDATE UTC_TIMESTAMP(),
    CONSTRAINT uq_plans_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Billing plan tiers (Free, Starter, Growth, Enterprise) defining usage limits and pricing. Referenced by companies.plan_id.';

-- -----------------------------------------------------------------------------
-- permissions: fine-grained RBAC permission catalog (e.g. 'conversations.view',
-- 'channel_accounts.manage'). role_permissions / user_roles / roles are created
-- in 0003_identity_roles.sql, once `users` and `roles` exist.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(150) NOT NULL,             -- e.g. 'conversations.view', 'channel_accounts.manage'
    description     VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) ON UPDATE UTC_TIMESTAMP(),
    CONSTRAINT uq_permissions_slug UNIQUE (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fine-grained RBAC permission catalog (e.g. conversations.view, channel_accounts.manage). Assigned to roles via role_permissions (0003).';

-- -----------------------------------------------------------------------------
-- features: feature-flag catalog (e.g. 'ai_auto_reply', 'broadcasts', 'api_access',
-- 'departments'). plan_features / company_features (which reference this table
-- plus `plans` / `companies`) are created in 0014_billing.sql, once both exist.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS features (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(100) NOT NULL,             -- 'ai_auto_reply', 'broadcasts', 'api_access', 'departments'
    name            VARCHAR(150) NOT NULL,
    description     VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) ON UPDATE UTC_TIMESTAMP(),
    CONSTRAINT uq_features_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Feature-flag catalog (e.g. ai_auto_reply, broadcasts, api_access, departments) used to gate functionality per plan/workspace in 0014_billing.sql.';

-- -----------------------------------------------------------------------------
-- workspace_types: lookup table for the kind of tenant a workspace represents
-- (Individual, Team, Company, Organization). Deliberately a table, not an ENUM,
-- so each type can later carry its own metadata (e.g. default_max_users,
-- default_plan_id) without requiring a schema change -- same pattern as
-- `channels`/`providers`/`plans` above. Referenced by workspaces.type_id (0002).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workspace_types (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(30) NOT NULL,              -- 'individual', 'team', 'company', 'organization'
    name                VARCHAR(100) NOT NULL,
    description         VARCHAR(255) NULL,
    default_max_users   INT UNSIGNED NULL,                  -- optional soft default; plans still govern hard limits
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) ON UPDATE UTC_TIMESTAMP(),
    CONSTRAINT uq_workspace_types_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lookup catalog of workspace tenant kinds (Individual, Team, Company, Organization). Referenced by workspaces.type_id; same architecture serves all four with no structural differences.';

-- -----------------------------------------------------------------------------
-- workspace_statuses: lookup table for workspace lifecycle state (Active,
-- Trial, Pending, Suspended, Archived). A table (not an ENUM) so future
-- billing/subscription logic (0014_billing.sql) can attach behavior to a
-- status (e.g. blocks_login, is_billable) without a schema change.
-- Referenced by workspaces.status_id (0002).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workspace_statuses (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30) NOT NULL,              -- 'active', 'trial', 'pending', 'suspended', 'archived'
    name            VARCHAR(100) NOT NULL,
    description     VARCHAR(255) NULL,
    blocks_login    TINYINT(1) NOT NULL DEFAULT 0,      -- e.g. 'suspended'/'archived' may block member login in future auth checks
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) ON UPDATE UTC_TIMESTAMP(),
    CONSTRAINT uq_workspace_statuses_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lookup catalog of workspace lifecycle statuses (Active, Trial, Pending, Suspended, Archived). Referenced by workspaces.status_id; integrates with future billing/subscription management (0014_billing.sql). New workspaces default to the Pending status (Sprint 1 Product Backlog, Feature A3/B1).';

-- -----------------------------------------------------------------------------
-- workspace_member_statuses: lookup table for a membership's standing within a
-- workspace (Active, Suspended). Deliberately narrower than workspace_statuses
-- above -- there is no 'Invited'/'Pending' state here, because an invitation
-- that has not yet been accepted has no workspace_members row at all (see
-- workspace_invitations, 0003); this catalog only covers the lifecycle of a
-- membership that already exists. Replaces the earlier boolean
-- `workspace_members.is_active` design so status can carry future metadata
-- (e.g. suspension reason display text) without a schema change. Referenced by
-- workspace_members.status_id (0002).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS workspace_member_statuses (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30) NOT NULL,              -- 'active', 'suspended'
    name            VARCHAR(100) NOT NULL,
    description     VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    updated_at      DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()) ON UPDATE UTC_TIMESTAMP(),
    CONSTRAINT uq_workspace_member_statuses_code UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Lookup catalog of workspace membership statuses (Active, Suspended). Referenced by workspace_members.status_id; deliberately narrower than workspace_statuses -- invitations (pre-membership) are tracked separately in workspace_invitations (0003).';
