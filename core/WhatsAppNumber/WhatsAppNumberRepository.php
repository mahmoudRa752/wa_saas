<?php
namespace Core\WhatsAppNumber;

use mysqli;

class WhatsAppNumberRepository
{
    public function __construct(private mysqli $conn)
    {
    }

    public function getPhoneNumberIdByUserId(int $userId): ?string
    {
        $stmt = $this->conn->prepare("SELECT phone_number_id FROM whatsapp_numbers WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ? $result['phone_number_id'] : null;
    }
}
