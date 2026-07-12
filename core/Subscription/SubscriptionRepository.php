<?php

namespace Core\Subscription;

use mysqli;

class SubscriptionRepository
{
    public function __construct(private mysqli $conn)
    {
    }

    public function getAllPlans(): array
    {
        $result = $this->conn->query("SELECT * FROM plans ORDER BY price ASC");
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function activate(int $companyId, int $planId, string $startDate, string $endDate): void
    {
        // Delete existing subscription first
        $del = $this->conn->prepare("DELETE FROM subscriptions WHERE company_id = ?");
        $del->bind_param("i", $companyId);
        $del->execute();
        $del->close();

        // Insert new subscription
        $stmt = $this->conn->prepare("INSERT INTO subscriptions (company_id, plan_id, start_date, end_date) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $companyId, $planId, $startDate, $endDate);
        $stmt->execute();
        $stmt->close();
    }
}
