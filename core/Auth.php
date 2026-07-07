<?php

require_once __DIR__ . '/Session.php';

/**
 * Auth.php
 *
 * Responsibility (Single Responsibility Principle):
 * Read-only accessor for "who is currently logged in", built entirely on
 * top of the EXISTING legacy session keys (`company_id`, `user_id`, `role`,
 * `user_name`, `company_name`). This class does not perform login, logout,
 * password checking, or session mutation — it only answers questions about
 * the identity already established by the legacy `auth/login*.php` flows.
 *
 * WHY THIS EXISTS
 * ----------------
 * Today every dashboard/api file repeats its own inline check, e.g.:
 *   if (!isset($_SESSION['company_id'])) { header("Location: ../auth/login.php"); exit; }
 *   if ($_SESSION['role'] != 'admin') { ... }
 * `Auth` gives future code (Services, Controllers, Middleware) one place
 * to ask "is someone logged in?" / "are they an admin?" instead of
 * duplicating that logic. It changes NOTHING about how login/logout work.
 *
 * BACKWARD COMPATIBILITY
 * -----------------------
 * - Does not rename or remove `company_id`, `user_id`, or `role`.
 * - Does not require any existing file to change.
 * - Not called from any existing file yet (see integration plan Phase 3,
 *   where inline guards are swapped for calls like `Auth::isLoggedIn()`
 *   one file at a time).
 *
 * KNOWN LEGACY QUIRK THIS CLASS DELIBERATELY EXPOSES AS-IS
 * -----------------------------------------------------------
 * The company owner/admin has no `users` row and therefore no `user_id`
 * (see integration_phase1_analysis.md §3). `Auth::userId()` legitimately
 * returns null for an admin today — this is existing behavior, not a bug
 * introduced here.
 *
 * @package Core
 */
final class Auth
{
    /**
     * Whether any identity (admin or employee) is currently logged in.
     *
     * Equivalent to the legacy `isset($_SESSION['company_id'])` check used
     * throughout `dashboard/*.php` and `api/*.php` today.
     *
     * @return bool
     */
    public static function isLoggedIn(): bool
    {
        return Session::has('company_id');
    }

    /**
     * The tenant identifier for the current session.
     *
     * Set for BOTH admin and employee logins today (`companies.id` for an
     * admin, `users.company_id` for an employee) — see analysis §3.
     *
     * @return int|null
     */
    public static function companyId(): ?int
    {
        $value = Session::get('company_id');
        return $value === null ? null : (int) $value;
    }

    /**
     * The `users.id` of the current employee, or null for an admin
     * (admins have no `users` row today — existing, pre-existing quirk).
     *
     * @return int|null
     */
    public static function userId(): ?int
    {
        $value = Session::get('user_id');
        return $value === null ? null : (int) $value;
    }

    /**
     * Raw legacy role string as currently stored in session:
     * either "admin" or "employee". Returns null if not logged in.
     *
     * @return string|null
     */
    public static function role(): ?string
    {
        return Session::get('role');
    }

    /**
     * Convenience check mirroring the legacy `$_SESSION['role'] == 'admin'`
     * comparisons scattered across the dashboard/api files today.
     *
     * @return bool
     */
    public static function isAdmin(): bool
    {
        return self::role() === 'admin';
    }

    /**
     * Convenience check mirroring the legacy `$_SESSION['role'] == 'employee'`
     * comparisons.
     *
     * @return bool
     */
    public static function isEmployee(): bool
    {
        return self::role() === 'employee';
    }

    /**
     * Display name for the current session, matching whichever of
     * `user_name` / `company_name` the legacy login flow populated.
     *
     * @return string|null
     */
    public static function displayName(): ?string
    {
        return Session::get('user_name') ?? Session::get('company_name');
    }
}
