<?php

namespace Core\Auth;

class CsrfHelper
{
    public static function generateToken(string $formName = 'default'): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_tokens'][$formName])) {
            $_SESSION['csrf_tokens'][$formName] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_tokens'][$formName];
    }

    public static function validateToken(?string $token, string $formName = 'default'): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($token) || empty($_SESSION['csrf_tokens'][$formName])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_tokens'][$formName], $token);
    }
}
