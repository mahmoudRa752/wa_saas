<?php
/**
 * WA Manager — Audit Log Service
 * File: core/Services/AuditLogService.php
 */
namespace Core\Services;

class AuditLogService {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Log a company-specific action to the audit logs table.
     */
    public function log(int $companyId, ?int $userId, string $actionType, string $description) {
        $stmt = $this->db->prepare("
            INSERT INTO company_audit_logs (company_id, user_id, action_type, description) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("iiss", $companyId, $userId, $actionType, $description);
        $stmt->execute();
        $stmt->close();
    }
}
