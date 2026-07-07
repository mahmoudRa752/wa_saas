<?php

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Workspace.php';

/**
 * Permission.php
 *
 * Responsibility (Single Responsibility Principle):
 * Single point of truth for "is the current identity allowed to do X",
 * for NEW code. Its job is to eventually replace the copy-pasted inline
 * checks like `$_SESSION['role'] != 'admin'` found in every
 * `dashboard/*.php` / `api/*.php` file today, per Phase 3 of the approved
 * Integration Plan.
 *
 * CURRENT STATE (Core Layer delivery — this file only)
 * -------------------------------------------------------
 * The real RBAC tables (`roles`, `permissions`, `role_permissions`,
 * `workspace_member_roles`) already exist as of migration `0003`, but no
 * Repository layer exists yet to query them (Repositories are scaffolded
 * as an empty folder in this delivery, per scope — no business logic goes
 * in yet). Querying the database from this class today would be a
 * dependency this delivery is not authorized to add (`db.php` inclusion,
 * live query, error handling) without a Repository/Service in place, and
 * it is explicitly out of scope ("do not modify existing functionality").
 *
 * Therefore `Permission::has()` currently uses a documented, TEMPORARY
 * legacy-equivalent fallback: it mirrors today's inline
 * `$_SESSION['role'] == 'admin'` checks via `Auth::isAdmin()`, so that:
 *   - the method is safe to call from anywhere today (never throws, never
 *     hits the DB, never changes behavior for any existing page since
 *     nothing existing calls it yet);
 *   - when Phase 3 introduces a `RoleRepository`/`PermissionService`
 *     backed by `role_permissions`/`workspace_member_roles`, only the
 *     inside of this method needs to change — every call site written
 *     against `Permission::has(...)` keeps working unmodified.
 *
 * This fallback-first design is the same "degrade to legacy behavior
 * instead of hard failure" approach approved in the Phase 3 plan
 * (integration_phase1_analysis.md, Phase 3).
 *
 * @package Core
 */
final class Permission
{
    /**
     * Checks whether the current identity holds the given permission key.
     *
     * @param string $permissionKey Dot-style key matching the `permissions`
     *                              catalog seeded in migration `0001`
     *                              (e.g. "employees.manage",
     *                              "whatsapp_numbers.reassign"). Not yet
     *                              enforced against real RBAC data — see
     *                              class docblock for the temporary
     *                              legacy-equivalent fallback in effect.
     * @return bool
     */
    public static function has(string $permissionKey): bool
    {
        // TEMPORARY fallback (Phase 3 will replace this method body only):
        // today's application has exactly two roles, "admin" and
        // "employee", and every existing admin-only inline check is
        // effectively "is this session an admin". Until a Repository
        // resolves real per-permission grants from
        // `role_permissions`/`workspace_member_roles`, treat admin as
        // holding every permission and employee as holding none. This is
        // intentionally coarse and intentionally documented as temporary.
        if (!Auth::isLoggedIn()) {
            return false;
        }

        return Auth::isAdmin();
    }

    /**
     * Convenience wrapper mirroring the common legacy pattern of gating a
     * whole page on a single role string (e.g. "admin").
     *
     * @param string $role Expected legacy role string ("admin" | "employee").
     * @return bool
     */
    public static function hasRole(string $role): bool
    {
        return Auth::isLoggedIn() && Auth::role() === $role;
    }
}
