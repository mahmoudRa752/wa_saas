<?php
/**
 * WA Manager — IP Rate Limiting Security Utility
 * File: core/Auth/RateLimiter.php
 */
namespace Core\Auth;

class RateLimiter {
    private static $limitDir = __DIR__ . '/../../logs/rate_limits';

    /**
     * Check if client IP has exceeded request limit for a given context key.
     * Returns true if request is allowed, false if blocked (HTTP 429).
     */
    public static function check(string $key, int $maxRequests = 60, int $period = 60): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $cleanIp = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $ip);
        
        if (!is_dir(self::$limitDir)) {
            @mkdir(self::$limitDir, 0777, true);
        }

        $file = self::$limitDir . "/{$key}_{$cleanIp}.json";
        $now = time();

        $timestamps = [];
        if (file_exists($file)) {
            $timestamps = json_decode(file_get_contents($file), true) ?: [];
        }

        // Keep timestamps within the window
        $timestamps = array_filter($timestamps, function($ts) use ($now, $period) {
            return ($now - $ts) < $period;
        });

        if (count($timestamps) >= $maxRequests) {
            return false;
        }

        $timestamps[] = $now;
        @file_put_contents($file, json_encode(array_values($timestamps)));
        return true;
    }
}
