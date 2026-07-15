<?php

namespace Core\Services;

class WhatsAppService
{
    public function __construct(private ?string $accessToken = null)
    {
    }

    public function setAccessToken(?string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    public function sendPayload(string $phoneNumberId, array $payload, ?string $accessToken = null): array
    {
        $token = $accessToken ?? $this->accessToken ?? getenv('WHATSAPP_ACCESS_TOKEN') ?: getenv('WHATSAPP_TOKEN') ?: (defined('WHATSAPP_TOKEN') ? WHATSAPP_TOKEN : null);
        
        // Resolve Graph API Version from environment
        $apiVersion = getenv('GRAPH_API_VERSION') ?: 'v20.0';
        if (strpos($apiVersion, 'v') !== 0) {
            $apiVersion = 'v' . $apiVersion;
        }
        
        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Structured diagnostic logging for WHATSAPP_DEBUG_REPORT
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'request_url' => $url,
            'headers' => [
                'Authorization' => 'Bearer ' . substr($token, 0, 15) . '...' . substr($token, -10),
                'Content-Type' => 'application/json'
            ],
            'payload' => $payload,
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response' => json_decode($response, true) ?: $response
        ];
        
        @file_put_contents($logDir . '/whatsapp_api.log', json_encode($logData) . "\n", FILE_APPEND);

        if ($httpCode < 200 || $httpCode >= 300) {
            require_once(__DIR__ . '/LoggerService.php');
            LoggerService::logFailedWhatsApp($phoneNumberId, $payload['to'] ?? 'unknown', $httpCode, $response ?? '', $payload);
        }

        return [
            'httpCode' => $httpCode,
            'response' => $response,
            'curlError' => $curlError
        ];
    }

    public function sendText(string $phoneNumberId, string $recipient, string $message, ?string $accessToken = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'text',
            'text' => ['body' => $message],
        ];

        return $this->sendPayload($phoneNumberId, $payload, $accessToken);
    }

    public function sendTemplate(string $phoneNumberId, string $recipient, ?string $accessToken = null, string $templateName = 'hello_world', string $languageCode = 'en_US', array $components = []): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $languageCode],
            ],
        ];

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        return $this->sendPayload($phoneNumberId, $payload, $accessToken);
    }

    public function editMessage(string $phoneNumberId, string $wamid, string $newBody, ?string $accessToken = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'edited',
            'message_id' => $wamid,
            'text' => ['body' => $newBody],
        ];

        return $this->sendPayload($phoneNumberId, $payload, $accessToken);
    }

    public function deleteMessage(string $phoneNumberId, string $wamid, ?string $accessToken = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'deleted',
            'message_id' => $wamid,
        ];

        return $this->sendPayload($phoneNumberId, $payload, $accessToken);
    }
}
