<?php

require_once __DIR__ . '/Session.php';

/**
 * Workspace.php
 *
 * Responsibility (Single Responsibility Principle):
 * Single point of truth for resolving "which Workspace (tenant) is the
 * current request operating in", for NEW code written against the target
 * Workspace/Membership architecture (Sprint 1 tables: `workspaces`,
 * `workspace_members`, `roles`, `workspace_member_roles`).
 *
 * CURRENT STATE (Core Layer delivery — this file only)
 * -------------------------------------------------------
 * Per the approved Integration Plan, "Workspace Context" is populated in
 * session (`$_SESSION['workspace_ctx']`) starting in Phase 2, once
 * `auth/login*.php` are edited to write it. That edit is explicitly OUT
 * OF SCOPE for this Core Layer delivery — no existing file is modified
 * here. Today, `workspace_ctx` is never set by any running code, so:
 *
 *   Workspace::current()  → always returns null right now.
 *
 * That is the CORRECT and EXPECTED behavior for this delivery: the class
 * exists and is safe to call from anywhere, but has nothing to resolve
 * yet because nothing populates its input. Once Phase 2 wires
 * `auth/login*.php` to call `Workspace::rememberContext()` (or equivalent)
 * after legacy login succeeds, this class starts returning real data with
 * no changes needed to this file.
 *
 * WHY A SEPARATE SESSION KEY (`workspace_ctx`) INSTEAD OF REUSING
 * `company_id`
 * -----------------------------------------------------------------------
 * (a) Why chosen: keeps the new context fully additive — legacy code that
 *     reads `company_id`/`role` directly is completely unaffected,
 *     satisfying the "coexist" constraint of the Strangler Fig Pattern.
 * (b) Alternatives considered: (1) overwrite/repurpose `company_id` to
 *     mean `workspace_id` directly; (2) resolve workspace context on every
 *     request via a live DB join instead of caching it in session.
 * (c) Why rejected: (1) risks silently breaking any of the ~12+ files that
 *     assume `company_id` is a `companies.id` value, violating the
 *     backward-compatibility rule; (2) adds a DB round-trip to every
 *     request for data that only changes on login/role-change, hurting
 *     performance for zero benefit at current scale.
 * (d) Impact: scalability — negligible (one small session payload);
 *     security — no new attack surface (still server-side session data);
 *     performance — better than per-request resolution; backward
 *     compatibility — full, since legacy keys are untouched.
 *
 * @package Core
 */
final class Workspace
{
    /**
     * Session key used to store the resolved workspace context.
     * Intentionally separate from all legacy keys (see class docblock).
     */
    private const SESSION_KEY = 'workspace_ctx';

    /**
     * Returns the current workspace context, or null if none has been
     * resolved yet (always null until Phase 2 wires login to populate it).
     *
     * Expected shape once populated (Phase 2+):
     * [
     *   'workspace_id' => int,
     *   'member_id'    => int,   // workspace_members.id
     *   'user_id'      => int,   // users.id (new users table)
     * ]
     *
     * @return array<string, int>|null
     */
    public static function current(): ?array
    {
        $context = Session::get(self::SESSION_KEY);
        return is_array($context) ? $context : null;
    }

    /**
     * Stores a resolved workspace context in session.
     *
     * Not called by any existing file today. Intended to be called by the
     * Phase 1 bootstrap helper immediately after a successful legacy login,
     * once Phase 2 is approved and implemented.
     *
     * @param int $workspaceId
     * @param int $memberId
     * @param int $userId
     * @return void
     */
    public static function rememberContext(int $workspaceId, int $memberId, int $userId): void
    {
        Session::set(self::SESSION_KEY, [
            'workspace_id' => $workspaceId,
            'member_id'    => $memberId,
            'user_id'      => $userId,
        ]);
    }

    /**
     * Convenience accessor for the resolved workspace id, or null.
     *
     * @return int|null
     */
    public static function id(): ?int
    {
        $context = self::current();
        return $context['workspace_id'] ?? null;
    }

    /**
     * Convenience accessor for the resolved workspace_members.id, or null.
     *
     * @return int|null
     */
    public static function memberId(): ?int
    {
        $context = self::current();
        return $context['member_id'] ?? null;
    }

    /**
     * Clears the workspace context (e.g. on logout). Does not touch any
     * legacy session key.
     *
     * @return void
     */
    public static function clearContext(): void
    {
        Session::remove(self::SESSION_KEY);
    }
}
