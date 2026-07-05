-- =============================================================================
-- Migration: 0000_schema_versions.sql
-- Purpose  : Creates the `schema_versions` table -- the authoritative log of
--            which migrations have been applied to this database, when, by
--            whom, and whether they succeeded. Every migration from 0001
--            onward ends with an INSERT registering itself here.
--
-- Depends on : nothing. This is the very first file to run, before any other
--              migration in this project.
--
-- Touches existing tables? No. Adds one new table only.
--
-- Idempotency / transaction note:
--   The CREATE TABLE below is DDL and therefore cannot be wrapped in a real,
--   rollback-able transaction in MySQL/InnoDB (implicit commit applies -- see
--   0001's header for the full explanation). It uses IF NOT EXISTS so this
--   file is always safe to re-run.
--   The self-registration INSERT at the bottom IS pure DML, so it IS wrapped
--   in a real transaction with rollback on failure, and is written so it can
--   be re-run without creating a duplicate row (ON DUPLICATE KEY UPDATE on
--   the unique `migration_name` key).
--
-- Database standards applied in this file (project-wide, effective now):
--   - ENGINE=InnoDB, DEFAULT CHARSET=utf8mb4, COLLATE=utf8mb4_unicode_ci
--   - BIGINT UNSIGNED AUTO_INCREMENT primary key
--   - Descriptively named unique key
--   - Table-level COMMENT describing purpose
--   - All timestamps stored in UTC via UTC_TIMESTAMP() (NOT CURRENT_TIMESTAMP,
--     which follows the MySQL server/session timezone -- UTC_TIMESTAMP() is
--     always UTC regardless of server configuration)
--   - No UUID column: this is an internal ops/audit table, never exposed via
--     any public or business API (standard #9 exempts it)
--   - No updated_at/deleted_at: this table is an append-only log, not a
--     business entity (standard #7 applies to business entities; rows here
--     are never updated or soft-deleted once written)
-- =============================================================================

-- -----------------------------------------------------------------------------
-- schema_versions: append-only record of every migration file that has been
-- applied to this database, in the order it was applied, with enough metadata
-- (checksum, timing, outcome) to audit and debug deployments.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_versions (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration_name      VARCHAR(150) NOT NULL,                  -- e.g. '0001_lookup_catalogs.sql'
    checksum            CHAR(64) NULL,                          -- SHA-256 hex of the file's contents when applied (drift detection)
    applied_at          DATETIME NOT NULL DEFAULT (UTC_TIMESTAMP()),
    applied_by          VARCHAR(100) NULL,                      -- deploy user / CI job name / 'manual'
    execution_time_ms   INT UNSIGNED NULL,
    status              ENUM('success', 'failed') NOT NULL DEFAULT 'success',
    notes               VARCHAR(255) NULL,

    CONSTRAINT uq_schema_versions_migration_name UNIQUE (migration_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Append-only log of applied database migrations: name, checksum, when/by whom applied, and outcome.';

-- -----------------------------------------------------------------------------
-- Self-registration: record that this file itself has been applied.
-- Wrapped in a real transaction since this is pure DML (fully supported by
-- InnoDB, unlike the CREATE TABLE above). Safe to re-run: ON DUPLICATE KEY
-- UPDATE on the unique `migration_name` key means a second run is a no-op
-- (aside from refreshing applied_at), never a duplicate row or an error.
-- -----------------------------------------------------------------------------
START TRANSACTION;

INSERT INTO schema_versions (migration_name, applied_by, status, notes)
VALUES ('0000_schema_versions.sql', 'manual', 'success', 'Bootstrap migration: creates the schema_versions tracking table itself.')
ON DUPLICATE KEY UPDATE
    applied_at = UTC_TIMESTAMP(),
    status     = VALUES(status),
    notes      = VALUES(notes);

COMMIT;
