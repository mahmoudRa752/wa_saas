<?php
/**
 * Session.php
 *
 * Responsibility (Single Responsibility Principle):
 * Thin, dependency-free wrapper around native PHP sessions ($_SESSION).
 * This is the ONLY class in the Core Layer that is allowed to touch
 * `session_*()` functions or read/write `$_SESSION` directly. Every other
 * Core/Service/Repository/Controller class must go through this class
 * instead of touching `$_SESSION` directly, so session handling can later
 * be swapped (e.g. DB-backed sessions, Redis) without touching call sites.
 *
 * BACKWARD COMPATIBILITY
 * -----------------------
 * This class does NOT rename, remove, or restructure any existing session
 * key (`company_id`, `user_id`, `role`, `user_name`, `company_name`, etc.).
 * It is a pure accessor layer. Legacy files that read/write `$_SESSION`
 * directly today continue to work completely unmodified; this class simply
 * gives NEW code a safe, centralized way to do the same thing.
 *
 * It is not wired into any existing file yet. Nothing calls this class
 * today, so introducing it changes zero existing behavior.
 *
 * @package Core
 */
final class Session
{
    /**
     * Starts the PHP session if one is not already active.
     *
     * Mirrors the existing guarded pattern already used in
     * `layouts/header.php` (`session_status() === PHP_SESSION_NONE`), so
     * calling this from new code is always safe even on a page that has
     * already called the legacy unconditional `session_start()`.
     *
     * @return void
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Reads a session value.
     *
     * @param string $key     Session key, e.g. "company_id", "user_id", "role".
     * @param mixed  $default Value returned when the key is not set.
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Writes a session value.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Checks whether a session key is set (mirrors `isset($_SESSION[$key])`).
     *
     * @param string $key
     * @return bool
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }

    /**
     * Removes a single session key without touching any other key.
     *
     * Useful for Phase 2+ so a new key (e.g. `workspace_ctx`) can be
     * cleared on logout without altering how legacy logout files clear
     * legacy keys.
     *
     * @param string $key
     * @return void
     */
    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    /**
     * Fully destroys the session: clears the `$_SESSION` array, expires the
     * session cookie, then destroys the session on the server.
     *
     * NOTE: This is a safer sequence than the current `auth/logout.php`
     * (which calls `session_destroy()` directly without clearing the array
     * or cookie first). This method is NOT wired into `auth/logout.php`
     * automatically — that swap is a Phase 2/3 decision, not part of this
     * Core Layer delivery, per the "do not modify existing files" scope.
     *
     * @return void
     */
    public static function destroy(): void
    {
        self::start();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }
}
