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

    public function createCompany(string $name, string $email, string $passwordHash): int|string
    {
        $companyCode = substr(md5(uniqid(mt_rand(), true)), 0, 8); // Generate an 8-character code
        $stmt = $this->conn->prepare("INSERT INTO companies (name, email, password, company_code) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $name, $email, $passwordHash, $companyCode);
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
