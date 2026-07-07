<?php

/**
 * Response.php
 *
 * Responsibility (Single Responsibility Principle):
 * Small, dependency-free helper for producing consistent HTTP responses
 * (JSON API responses and redirects) from NEW code (Controllers, Services,
 * `api/*.php` endpoints written going forward).
 *
 * WHY THIS EXISTS
 * ----------------
 * Existing `api/*.php` files (e.g. `send_chat.php`, `get_messages.php`)
 * each hand-roll `header('Content-Type: application/json')` +
 * `echo json_encode(...)` + `exit`. This class exists so future endpoints
 * don't repeat that boilerplate — it does NOT change how any existing
 * endpoint responds, and it is not called from any existing file yet.
 *
 * BACKWARD COMPATIBILITY
 * -----------------------
 * `send_chat.php` / `get_messages.php` response shapes and status codes
 * are contractually fixed per your standing rule. This class is not used
 * by those files in this delivery, so their JSON contracts are completely
 * unaffected. If/when they are ever migrated to use this helper (a future,
 * separately-approved change), the exact same JSON shape and field names
 * they emit today must be preserved by the caller.
 *
 * @package Core
 */
final class Response
{
    /**
     * Emits a JSON response with the given HTTP status code and exits.
     *
     * @param mixed $data       Any JSON-encodable value.
     * @param int   $statusCode HTTP status code, default 200.
     * @return never
     */
    public static function json(mixed $data, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Emits a JSON success envelope: {"success": true, "data": ...}.
     *
     * @param mixed $data
     * @param int   $statusCode
     * @return never
     */
    public static function success(mixed $data = null, int $statusCode = 200): never
    {
        self::json(['success' => true, 'data' => $data], $statusCode);
    }

    /**
     * Emits a JSON error envelope: {"success": false, "error": "..."}.
     *
     * @param string $message
     * @param int    $statusCode
     * @return never
     */
    public static function error(string $message, int $statusCode = 400): never
    {
        self::json(['success' => false, 'error' => $message], $statusCode);
    }

    /**
     * Issues an HTTP redirect and exits.
     *
     * Mirrors the existing `header("Location: ...")); exit;` pattern used
     * throughout `dashboard/*.php` and `auth/*.php` today, without changing
     * any existing call site.
     *
     * @param string $url
     * @return never
     */
    public static function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }
}
