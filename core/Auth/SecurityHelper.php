<?php
/**
 * WA Manager — Production Security Hardening Headers Utility
 * File: core/Auth/SecurityHelper.php
 */
namespace Core\Auth;

class SecurityHelper {
    /**
     * Set secure headers and enforce session hardening.
     */
    public static function enforceHeaders() {
        // Prevent Clickjacking
        header("X-Frame-Options: SAMEORIGIN");
        
        // Prevent MIME sniffing
        header("X-Content-Type-Options: nosniff");
        
        // XSS Filter Protection
        header("X-XSS-Protection: 1; mode=block");
        
        // Referrer Privacy policy
        header("Referrer-Policy: strict-origin-when-cross-origin");
        
        // Session fixation protection
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['session_secured'])) {
                session_regenerate_id(true);
                $_SESSION['session_secured'] = true;
            }
        }
    }
}
