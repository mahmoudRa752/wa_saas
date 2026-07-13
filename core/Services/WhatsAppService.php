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
        $token = $accessToken ?? $this->accessToken;
        $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

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
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            require_once(__DIR__ . '/LoggerService.php');
            LoggerService::logFailedWhatsApp($phoneNumberId, $payload['to'] ?? 'unknown', $httpCode, $response ?? '', $payload);
        }

        return [
            'httpCode' => $httpCode,
            'response' => $response,
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

    public function sendTemplate(string $phoneNumberId, string $recipient, ?string $accessToken = null, string $templateName = 'hello_world', string $languageCode = 'en_US'): array
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
