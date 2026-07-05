-- =============================================================================
-- Migration: 0001_lookup_catalogs.sql
-- Purpose  : Creates the static, company-independent lookup/catalog tables that
--            everything else in the omnichannel schema will reference:
--              - channels    (WhatsApp, Telegram, Instagram, Messenger, Email, ...)
--              - providers   (Meta Cloud API, Evolution API, WAHA, 360Dialog, Twilio, ...)
--              - plans       (billing tiers; company_id relationship added in 0002/0014)
--              - permissions (fine-grained RBAC permission slugs)
--              - features    (feature-flag catalog; plan/company entitlement tables come in 0014)
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
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS channels (
    id              TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(30) NOT NULL UNIQUE,     -- 'whatsapp', 'telegram', 'instagram', 'messenger', 'email'
    name            VARCHAR(100) NOT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- providers: static catalog of vendor/API integrations that actually deliver a
-- channel (e.g. WhatsApp can be served by meta_cloud_api, evolution_api, waha,
-- 360dialog, or twilio). Seeded in 0021 alongside `channels`.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS providers (
    id                  TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(40) NOT NULL UNIQUE,      -- 'meta_cloud_api', 'evolution_api', 'waha', '360dialog', 'twilio'
    name                VARCHAR(100) NOT NULL,
    supports_channel_id TINYINT UNSIGNED NULL,            -- primary channel this provider serves (nullable; some providers, e.g. future email SMTP relays, may serve none directly)
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    code                    VARCHAR(60) NOT NULL UNIQUE,       -- 'free', 'starter', 'growth', 'enterprise'
    name                    VARCHAR(100) NOT NULL,
    max_channel_accounts    INT UNSIGNED NOT NULL DEFAULT 1,
    max_employees           INT UNSIGNED NOT NULL DEFAULT 5,
    max_ai_agents           INT UNSIGNED NOT NULL DEFAULT 0,
    monthly_price_cents     INT UNSIGNED NOT NULL DEFAULT 0,
    yearly_price_cents      INT UNSIGNED NOT NULL DEFAULT 0,
    currency                CHAR(3) NOT NULL DEFAULT 'USD',
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- permissions: fine-grained RBAC permission catalog (e.g. 'conversations.view',
-- 'channel_accounts.manage'). role_permissions / user_roles / roles are created
-- in 0003_identity_roles.sql, once `users` and `roles` exist.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS permissions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug            VARCHAR(150) NOT NULL UNIQUE,     -- e.g. 'conversations.view', 'channel_accounts.manage'
    description     VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- features: feature-flag catalog (e.g. 'ai_auto_reply', 'broadcasts', 'api_access',
-- 'departments'). plan_features / company_features (which reference this table
-- plus `plans` / `companies`) are created in 0014_billing.sql, once both exist.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS features (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(100) NOT NULL UNIQUE,      -- 'ai_auto_reply', 'broadcasts', 'api_access', 'departments'
    name            VARCHAR(150) NOT NULL,
    description     VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
