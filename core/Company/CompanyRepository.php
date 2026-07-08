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
}
