<?php

namespace Core\Employee;

use mysqli;

class EmployeeRepository
{
    public function __construct(private mysqli $conn)
    {
    }

    public function getWithPhoneNumbers(int $companyId): array
    {
        $stmt = $this->conn->prepare("
            SELECT u.id, u.name, u.email, w.phone_number_id
            FROM users u
            LEFT JOIN whatsapp_numbers w ON u.id = w.user_id
            WHERE u.company_id = ?
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $result;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }

    public function create(int $companyId, string $name, string $email, string $passwordHash): int
    {
        $stmt = $this->conn->prepare("
            INSERT INTO users (company_id, name, email, password, role)
            VALUES (?, ?, ?, ?, 'employee')
        ");
        $stmt->bind_param("isss", $companyId, $name, $email, $passwordHash);
        $stmt->execute();
        $id = (int)$stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function update(int $userId, int $companyId, string $name, string $email): void
    {
        $stmt = $this->conn->prepare("UPDATE users SET name=?, email=? WHERE id=? AND company_id=?");
        $stmt->bind_param("ssii", $name, $email, $userId, $companyId);
        $stmt->execute();
        $stmt->close();
    }

    public function delete(int $userId, int $companyId): void
    {
        $stmt = $this->conn->prepare("DELETE FROM users WHERE id = ? AND company_id = ?");
        $stmt->bind_param("ii", $userId, $companyId);
        $stmt->execute();
        $stmt->close();
    }

    public function upsertPhoneNumber(int $userId, string $phoneNumberId): void
    {
        $check = $this->conn->prepare("SELECT id FROM whatsapp_numbers WHERE user_id = ?");
        $check->bind_param("i", $userId);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($exists) {
            $stmt = $this->conn->prepare("UPDATE whatsapp_numbers SET phone_number_id=? WHERE user_id=?");
            $stmt->bind_param("si", $phoneNumberId, $userId);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO whatsapp_numbers (user_id, phone_number_id) VALUES (?, ?)");
            $stmt->bind_param("is", $userId, $phoneNumberId);
        }
        $stmt->execute();
        $stmt->close();
    }

    public function deletePhoneNumbers(int $userId): void
    {
        $stmt = $this->conn->prepare("DELETE FROM whatsapp_numbers WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }
}
