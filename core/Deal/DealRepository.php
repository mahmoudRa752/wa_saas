<?php
/**
 * WA Manager — Sales Deal Database Repository
 * File: core/Deal/DealRepository.php
 */
namespace Core\Deal;

use Core\TenantContext;
use mysqli;

class DealRepository
{
    public function __construct(private readonly mysqli $db) {}

    public function getDbConnection(): mysqli
    {
        return $this->db;
    }

    public function findById(int $id, TenantContext $ctx, ?string $role = null, ?int $userId = null): ?Deal
    {
        $companyId = $ctx->companyId;
        if ($role === 'employee') {
            $stmt = $this->db->prepare("
                SELECT * FROM deals 
                WHERE id = ? AND company_id = ? AND assigned_to = ? 
                LIMIT 1
            ");
            if (!$stmt) return null;
            $stmt->bind_param("iii", $id, $companyId, $userId);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM deals 
                WHERE id = ? AND company_id = ? 
                LIMIT 1
            ");
            if (!$stmt) return null;
            $stmt->bind_param("ii", $id, $companyId);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? Deal::fromRow($row) : null;
    }

    public function findByCustomerId(TenantContext $ctx, int $customerId): ?Deal
    {
        $companyId = $ctx->companyId;
        $stmt = $this->db->prepare("
            SELECT * FROM deals 
            WHERE company_id = ? AND customer_id = ? 
            LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param("ii", $companyId, $customerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? Deal::fromRow($row) : null;
    }

    public function create(Deal $deal): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO deals (
                company_id, customer_id, assigned_to, stage_id, estimated_value,
                currency, probability, expected_close_date, source, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) return 0;

        $stmt->bind_param(
            "iiiidsisssi",
            $deal->companyId,
            $deal->customerId,
            $deal->assignedTo,
            $deal->stageId,
            $deal->estimatedValue,
            $deal->currency,
            $deal->probability,
            $deal->expectedCloseDate,
            $deal->source,
            $deal->notes,
            $deal->createdBy
        );

        $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();

        return $insertId;
    }

    public function update(Deal $deal): bool
    {
        $stmt = $this->db->prepare("
            UPDATE deals SET
                assigned_to = ?,
                stage_id = ?,
                estimated_value = ?,
                currency = ?,
                probability = ?,
                expected_close_date = ?,
                source = ?,
                notes = ?
            WHERE id = ? AND company_id = ?
        ");
        if (!$stmt) return false;

        $stmt->bind_param(
            "iidsisssii",
            $deal->assignedTo,
            $deal->stageId,
            $deal->estimatedValue,
            $deal->currency,
            $deal->probability,
            $deal->expectedCloseDate,
            $deal->source,
            $deal->notes,
            $deal->id,
            $deal->companyId
        );

        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }

    public function delete(int $id, TenantContext $ctx): bool
    {
        $companyId = $ctx->companyId;
        $stmt = $this->db->prepare("DELETE FROM deals WHERE id = ? AND company_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $id, $companyId);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }

    // ── Pipeline Stages ──

    public function getStages(TenantContext $ctx): array
    {
        $companyId = $ctx->companyId;
        $stmt = $this->db->prepare("
            SELECT * FROM deal_stages 
            WHERE company_id = ? 
            ORDER BY sort_order ASC, id ASC
        ");
        if (!$stmt) return [];
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    public function createStage(TenantContext $ctx, string $name, int $sortOrder): int
    {
        $companyId = $ctx->companyId;
        $stmt = $this->db->prepare("
            INSERT INTO deal_stages (company_id, name, sort_order) 
            VALUES (?, ?, ?)
        ");
        if (!$stmt) return 0;
        $stmt->bind_param("isi", $companyId, $name, $sortOrder);
        $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();
        return $insertId;
    }

    public function updateStage(TenantContext $ctx, int $id, string $name, int $sortOrder): bool
    {
        $companyId = $ctx->companyId;
        $stmt = $this->db->prepare("
            UPDATE deal_stages SET name = ?, sort_order = ? 
            WHERE id = ? AND company_id = ?
        ");
        if (!$stmt) return false;
        $stmt->bind_param("siii", $name, $sortOrder, $id, $companyId);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }

    public function deleteStage(TenantContext $ctx, int $id): bool
    {
        $companyId = $ctx->companyId;
        $stmt = $this->db->prepare("DELETE FROM deal_stages WHERE id = ? AND company_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $id, $companyId);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }

    // ── Kanban / Deals by Stage ──

    public function getDealsByStage(TenantContext $ctx, int $stageId, ?string $role = null, ?int $userId = null): array
    {
        $companyId = $ctx->companyId;
        $sql = "
            SELECT d.*, c.full_name_ar, c.full_name_en, c.mobile, u.name AS employee_name
            FROM deals d
            JOIN customers c ON d.customer_id = c.id
            LEFT JOIN users u ON d.assigned_to = u.id
            WHERE d.company_id = ? AND d.stage_id = ?
        ";

        if ($role === 'employee') {
            $sql .= " AND d.assigned_to = ?";
        }
        $sql .= " ORDER BY d.updated_at DESC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];

        if ($role === 'employee') {
            $stmt->bind_param("iii", $companyId, $stageId, $userId);
        } else {
            $stmt->bind_param("ii", $companyId, $stageId);
        }

        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    // ── Timeline activities ──

    public function logActivity(int $companyId, int $dealId, string $type, string $description): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO deal_activities (company_id, deal_id, activity_type, description) 
            VALUES (?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("iiss", $companyId, $dealId, $type, $description);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function getActivities(int $dealId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM deal_activities 
            WHERE deal_id = ? 
            ORDER BY created_at DESC
        ");
        if (!$stmt) return [];
        $stmt->bind_param("i", $dealId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    // ── Follow-up module ──

    public function createFollowup(int $companyId, int $dealId, string $type, string $date, string $time, bool $reminder, string $notes): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO deal_followups (company_id, deal_id, type, followup_date, followup_time, reminder, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) return 0;
        $rem = $reminder ? 1 : 0;
        $stmt->bind_param("iisssis", $companyId, $dealId, $type, $date, $time, $rem, $notes);
        $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();
        return $insertId;
    }

    public function updateFollowup(int $companyId, int $id, bool $completed): bool
    {
        $comp = $completed ? 1 : 0;
        $stmt = $this->db->prepare("
            UPDATE deal_followups SET completed = ? 
            WHERE id = ? AND company_id = ?
        ");
        if (!$stmt) return false;
        $stmt->bind_param("iii", $comp, $id, $companyId);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }

    public function getFollowups(int $dealId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM deal_followups 
            WHERE deal_id = ? 
            ORDER BY followup_date ASC, followup_time ASC
        ");
        if (!$stmt) return [];
        $stmt->bind_param("i", $dealId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    // ── Dashboard reminders ──

    public function getReminders(TenantContext $ctx, int $userId, string $scope, ?string $role = null): array
    {
        $companyId = $ctx->companyId;
        $sql = "
            SELECT f.*, c.full_name_ar, c.full_name_en, c.mobile
            FROM deal_followups f
            JOIN deals d ON f.deal_id = d.id
            JOIN customers c ON d.customer_id = c.id
            WHERE f.company_id = ? AND f.completed = 0
        ";

        if ($role === 'employee') {
            $sql .= " AND d.assigned_to = ?";
        }

        if ($scope === 'today') {
            $sql .= " AND f.followup_date = CURRENT_DATE()";
        } elseif ($scope === 'overdue') {
            $sql .= " AND f.followup_date < CURRENT_DATE()";
        } elseif ($scope === 'tomorrow') {
            $sql .= " AND f.followup_date = DATE_ADD(CURRENT_DATE(), INTERVAL 1 DAY)";
        }

        $sql .= " ORDER BY f.followup_date ASC, f.followup_time ASC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];

        if ($role === 'employee') {
            $stmt->bind_param("ii", $companyId, $userId);
        } else {
            $stmt->bind_param("i", $companyId);
        }

        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    // ── Analytics ──

    public function getAdminKPIs(TenantContext $ctx): array
    {
        $companyId = $ctx->companyId;

        // Pipeline Value (Sum estimated_value of open deals i.e. not Won or Lost)
        $pipelineValRes = $this->db->query("
            SELECT SUM(d.estimated_value) 
            FROM deals d
            JOIN deal_stages s ON d.stage_id = s.id
            WHERE d.company_id = $companyId AND s.name NOT IN ('Won', 'Lost')
        ");
        $pipelineValue = (float)($pipelineValRes->fetch_row()[0] ?? 0.0);

        // Won / Lost counts
        $wonRes = $this->db->query("
            SELECT COUNT(*) FROM deals d JOIN deal_stages s ON d.stage_id = s.id 
            WHERE d.company_id = $companyId AND s.name = 'Won'
        ");
        $wonDeals = (int)($wonRes->fetch_row()[0] ?? 0);

        $lostRes = $this->db->query("
            SELECT COUNT(*) FROM deals d JOIN deal_stages s ON d.stage_id = s.id 
            WHERE d.company_id = $companyId AND s.name = 'Lost'
        ");
        $lostDeals = (int)($lostRes->fetch_row()[0] ?? 0);

        // Conversion Rate
        $totalClosed = $wonDeals + $lostDeals;
        $conversionRate = $totalClosed > 0 ? ($wonDeals / $totalClosed) * 100 : 0.0;

        // Average Deal Size (AVG estimated value of Won + Open deals)
        $avgSizeRes = $this->db->query("
            SELECT AVG(estimated_value) FROM deals WHERE company_id = $companyId
        ");
        $avgDealSize = (float)($avgSizeRes->fetch_row()[0] ?? 0.0);

        // Closing This Month
        $closingMonthRes = $this->db->query("
            SELECT COUNT(*) FROM deals 
            WHERE company_id = $companyId 
              AND YEAR(expected_close_date) = YEAR(CURRENT_DATE()) 
              AND MONTH(expected_close_date) = MONTH(CURRENT_DATE())
        ");
        $dealsClosingThisMonth = (int)($closingMonthRes->fetch_row()[0] ?? 0);

        return [
            'pipeline_value' => $pipelineValue,
            'won_deals' => $wonDeals,
            'lost_deals' => $lostDeals,
            'conversion_rate' => $conversionRate,
            'average_deal_size' => $avgDealSize,
            'closing_this_month' => $dealsClosingThisMonth
        ];
    }

    public function getEmployeeKPIs(TenantContext $ctx, int $userId): array
    {
        $companyId = $ctx->companyId;

        // My Deals (all deals assigned to employee)
        $myDealsRes = $this->db->query("
            SELECT COUNT(*) FROM deals WHERE company_id = $companyId AND assigned_to = $userId
        ");
        $myDeals = (int)($myDealsRes->fetch_row()[0] ?? 0);

        // Today's Follow-ups (uncompleted follow-ups for employee's deals today)
        $followRes = $this->db->query("
            SELECT COUNT(f.id) 
            FROM deal_followups f
            JOIN deals d ON f.deal_id = d.id
            WHERE f.company_id = $companyId 
              AND d.assigned_to = $userId 
              AND f.followup_date = CURRENT_DATE() 
              AND f.completed = 0
        ");
        $todaysFollowups = (int)($followRes->fetch_row()[0] ?? 0);

        // Won This Month (sum estimated value won by employee this month)
        $wonMonthRes = $this->db->query("
            SELECT SUM(d.estimated_value) 
            FROM deals d
            JOIN deal_stages s ON d.stage_id = s.id
            WHERE d.company_id = $companyId 
              AND d.assigned_to = $userId 
              AND s.name = 'Won'
              AND YEAR(d.updated_at) = YEAR(CURRENT_DATE())
              AND MONTH(d.updated_at) = MONTH(CURRENT_DATE())
        ");
        $wonThisMonth = (float)($wonMonthRes->fetch_row()[0] ?? 0.0);

        return [
            'my_deals' => $myDeals,
            'todays_followups' => $todaysFollowups,
            'won_this_month' => $wonThisMonth
        ];
    }

    // ── Reports ──

    public function getReportData(TenantContext $ctx, string $groupBy): array
    {
        $companyId = $ctx->companyId;
        $allowedGroups = ['employee', 'project', 'nationality', 'program', 'month'];
        if (!in_array($groupBy, $allowedGroups)) {
            return [];
        }

        if ($groupBy === 'employee') {
            $sql = "
                SELECT COALESCE(u.name, 'Unassigned') as label, SUM(d.estimated_value) as val, COUNT(d.id) as cnt
                FROM deals d
                LEFT JOIN users u ON d.assigned_to = u.id
                JOIN deal_stages s ON d.stage_id = s.id
                WHERE d.company_id = ? AND s.name = 'Won'
                GROUP BY d.assigned_to
            ";
        } elseif ($groupBy === 'project') {
            $sql = "
                SELECT COALESCE(c.project_name, 'No Project') as label, SUM(d.estimated_value) as val, COUNT(d.id) as cnt
                FROM deals d
                JOIN customers c ON d.customer_id = c.id
                JOIN deal_stages s ON d.stage_id = s.id
                WHERE d.company_id = ? AND s.name = 'Won'
                GROUP BY c.project_name
            ";
        } elseif ($groupBy === 'nationality') {
            $sql = "
                SELECT COALESCE(c.nationality, 'Unknown') as label, SUM(d.estimated_value) as val, COUNT(d.id) as cnt
                FROM deals d
                JOIN customers c ON d.customer_id = c.id
                JOIN deal_stages s ON d.stage_id = s.id
                WHERE d.company_id = ? AND s.name = 'Won'
                GROUP BY c.nationality
            ";
        } elseif ($groupBy === 'program') {
            $sql = "
                SELECT COALESCE(c.program_name, 'No Program') as label, SUM(d.estimated_value) as val, COUNT(d.id) as cnt
                FROM deals d
                JOIN customers c ON d.customer_id = c.id
                JOIN deal_stages s ON d.stage_id = s.id
                WHERE d.company_id = ? AND s.name = 'Won'
                GROUP BY c.program_name
            ";
        } elseif ($groupBy === 'month') {
            $sql = "
                SELECT DATE_FORMAT(d.updated_at, '%Y-%m') as label, SUM(d.estimated_value) as val, COUNT(d.id) as cnt
                FROM deals d
                JOIN deal_stages s ON d.stage_id = s.id
                WHERE d.company_id = ? AND s.name = 'Won'
                GROUP BY DATE_FORMAT(d.updated_at, '%Y-%m')
                ORDER BY label ASC
            ";
        }

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return [];
        $stmt->bind_param("i", $companyId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }

    /**
     * Fetch active deal details, last activity, and next follow-up date/time for a customer
     */
    public function getCustomerDealSummary(TenantContext $ctx, int $customerId): ?array
    {
        $companyId = $ctx->companyId;
        
        $stmt = $this->db->prepare("
            SELECT d.id, d.estimated_value, d.currency, d.expected_close_date, s.name as stage_name
            FROM deals d
            JOIN deal_stages s ON d.stage_id = s.id
            WHERE d.company_id = ? AND d.customer_id = ?
            LIMIT 1
        ");
        if (!$stmt) return null;
        $stmt->bind_param("ii", $companyId, $customerId);
        $stmt->execute();
        $dealRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$dealRow) return null;
        
        $dealId = (int)$dealRow['id'];
        
        // Fetch last activity
        $actStmt = $this->db->prepare("
            SELECT created_at FROM deal_activities 
            WHERE deal_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $lastActivity = '-';
        if ($actStmt) {
            $actStmt->bind_param("i", $dealId);
            $actStmt->execute();
            $actRow = $actStmt->get_result()->fetch_assoc();
            $lastActivity = $actRow['created_at'] ?? '-';
            $actStmt->close();
        }
        
        // Fetch next follow-up
        $folStmt = $this->db->prepare("
            SELECT followup_date, followup_time FROM deal_followups 
            WHERE deal_id = ? AND completed = 0 AND followup_date >= CURRENT_DATE()
            ORDER BY followup_date ASC, followup_time ASC 
            LIMIT 1
        ");
        $nextFollowup = '-';
        if ($folStmt) {
            $folStmt->bind_param("i", $dealId);
            $folStmt->execute();
            $folRow = $folStmt->get_result()->fetch_assoc();
            if ($folRow) {
                $nextFollowup = $folRow['followup_date'] . ' ' . $folRow['followup_time'];
            }
            $folStmt->close();
        }
        
        return [
            'stage_name' => $dealRow['stage_name'],
            'estimated_value' => $dealRow['estimated_value'],
            'currency' => $dealRow['currency'],
            'expected_close_date' => $dealRow['expected_close_date'],
            'last_activity' => $lastActivity,
            'next_followup' => $nextFollowup
        ];
    }
}
