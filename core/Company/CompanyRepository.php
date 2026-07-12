<?php
namespace Core\Company;

use mysqli;

class CompanyRepository
{
    public function __construct(private mysqli $conn)
    {
    }

    public function getEmployees(int $companyId): array
    {
        $stmt = $this->conn->prepare("SELECT id, name FROM users WHERE company_id = ?");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $employees = [];
        while ($row = $result->fetch_assoc()) {
            $employees[] = $row;
        }
        $stmt->close();
        
        return $employees;
    }

    public function getTotalEmployees(int $companyId): int
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as total FROM users WHERE company_id = ?");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $total = (int) ($stmt->get_result()->fetch_assoc()['total'] ?? 0);
        $stmt->close();
        return $total;
    }

    public function getById(int $companyId): ?array
    {
        $stmt = $this->conn->prepare("SELECT name, email, logo FROM companies WHERE id = ?");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    public function updateProfile(int $companyId, string $name, string $email, ?string $passwordHash = null): void
    {
        if ($passwordHash !== null) {
            $stmt = $this->conn->prepare("UPDATE companies SET name=?, email=?, password=? WHERE id=?");
            $stmt->bind_param("sssi", $name, $email, $passwordHash, $companyId);
        } else {
            $stmt = $this->conn->prepare("UPDATE companies SET name=?, email=? WHERE id=?");
            $stmt->bind_param("ssi", $name, $email, $companyId);
        }
        $stmt->execute();
        $stmt->close();
    }
}
