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

    public function getCompanyIdByPhoneNumberId(string $phoneNumberId): ?int
    {
        $stmt = $this->conn->prepare("
            SELECT u.company_id 
            FROM whatsapp_numbers wn 
            JOIN users u ON wn.user_id = u.id 
            WHERE wn.phone_number_id = ? LIMIT 1
        ");
        $stmt->bind_param("s", $phoneNumberId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $result ? (int)$result['company_id'] : null;
    }

    public function getPhoneNumberIdByCompanyId(int $companyId): ?string
    {
        $stmt = $this->conn->prepare("
            SELECT wn.phone_number_id 
            FROM whatsapp_numbers wn 
            JOIN users u ON wn.user_id = u.id 
            WHERE u.company_id = ? LIMIT 1
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        return $result ? $result['phone_number_id'] : null;
    }
}
