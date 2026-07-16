<?php
/**
 * WA Manager — Production Logger Service
 * File: core/Services/LoggerService.php
 */
namespace Core\Services;

class LoggerService {
    private static $logDir = __DIR__ . '/../../logs';
    
    /**
     * General logs writer
     */
    public static function log(string $level, string $message, array $context = []) {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0777, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = !empty($context) ? ' ' . json_encode($context) : '';
        $logLine = "[$timestamp] [$level] $message$contextJson\n";
        
        @file_put_contents(self::$logDir . '/app.log', $logLine, FILE_APPEND);
    }
    
    public static function info(string $message, array $context = []) {
        self::log('INFO', $message, $context);
    }
    
    public static function warning(string $message, array $context = []) {
        self::log('WARNING', $message, $context);
    }
    
    public static function error(string $message, array $context = []) {
        self::log('ERROR', $message, $context);
    }
    
    /**
     * WhatsApp-specific failed request logs writer
     */
    public static function logFailedWhatsApp(string $phoneNumberId, string $recipient, int $httpCode, string $response, array $payload = []) {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0777, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logData = [
            'timestamp' => $timestamp,
            'phone_number_id' => $phoneNumberId,
            'recipient' => $recipient,
            'http_code' => $httpCode,
            'response' => json_decode($response, true) ?: $response,
            'payload' => $payload
        ];
        
        $logLine = json_encode($logData) . "\n";
        @file_put_contents(self::$logDir . '/failed_whatsapp.log', $logLine, FILE_APPEND);
    }
}
