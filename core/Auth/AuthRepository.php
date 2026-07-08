<?php

namespace Core\Auth;

use mysqli;

class AuthRepository
{
    public function __construct(private mysqli $conn)
    {
    }

    public function findCompanyByEmail(string $email): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM companies WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function findEmployeeByEmail(string $email): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT u.*, c.name as company_name
            FROM users u
            JOIN companies c ON u.company_id = c.id
            WHERE u.email = ?
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function findCompanyByNameAndEmail(string $name, string $email): ?array
    {
        $stmt = $this->conn->prepare("SELECT id FROM companies WHERE name = ? OR email = ?");
        $stmt->bind_param("ss", $name, $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function createCompany(string $name, string $email, string $passwordHash): int
    {
        $stmt = $this->conn->prepare("INSERT INTO companies (name, email, password) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $name, $email, $passwordHash);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public function findEmployeeByEmailForCompany(string $email, int $companyId): ?array
    {
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ? AND company_id = ?");
        $stmt->bind_param("si", $email, $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function createEmployee(string $name, string $email, string $passwordHash, int $companyId): int
    {
        $stmt = $this->conn->prepare("INSERT INTO users (name, email, password, company_id) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $name, $email, $passwordHash, $companyId);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }
}
