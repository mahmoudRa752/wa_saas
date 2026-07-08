<?php

namespace Core\Services;

use mysqli;

class UsageService
{
    public function __construct(private readonly mysqli $db)
    {
    }

    public function getMonthlyLimitAndUsage(int $companyId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.monthly_limit
            FROM companies c
            JOIN plans p ON c.plan_id = p.id
            WHERE c.id = ?
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $limit = $stmt->get_result()->fetch_assoc()['monthly_limit'] ?? 0;
        $stmt->close();

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE u.company_id = ?
            AND MONTH(m.sent_at) = MONTH(CURRENT_DATE())
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $used = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();

        return [
            'limit' => $limit,
            'used' => $used,
        ];
    }

    public function getSubscriptionUsageSummary(int $companyId): array
    {
        $stmt = $this->db->prepare("
            SELECT p.name, p.monthly_limit, s.end_date
            FROM subscriptions s
            JOIN plans p ON s.plan_id = p.id
            WHERE s.company_id = ? AND s.status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $subscription = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $planName = $subscription['name'] ?? 'No Plan';
        $limit = $subscription['monthly_limit'] ?? 0;
        $endDate = $subscription['end_date'] ?? null;

        $stmt = $this->db->prepare("
            SELECT COUNT(*) as total
            FROM messages m
            JOIN users u ON m.user_id = u.id
            WHERE u.company_id = ?
            AND MONTH(m.sent_at) = MONTH(CURRENT_DATE())
            AND YEAR(m.sent_at) = YEAR(CURRENT_DATE())
        ");
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $monthlyMessages = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt->close();

        $usagePercent = ($limit > 0) ? min(100, round(($monthlyMessages / $limit) * 100)) : 0;

        return [
            'planName' => $planName,
            'limit' => $limit,
            'endDate' => $endDate,
            'monthlyMessages' => $monthlyMessages,
            'usagePercent' => $usagePercent,
        ];
    }
}
