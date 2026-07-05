<?php
session_start();
require_once("../config/db.php");

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$company_id = $_SESSION['company_id'];

// إجمالي الرسائل
$stmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM messages m
    JOIN users u ON m.user_id = u.id
    WHERE u.company_id = ?
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$totalMessages = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// رسائل هذا الشهر
$stmt = $conn->prepare("
    SELECT COUNT(*) as total
    FROM messages m
    JOIN users u ON m.user_id = u.id
    WHERE u.company_id = ?
    AND MONTH(m.sent_at) = MONTH(CURRENT_DATE())
    AND YEAR(m.sent_at) = YEAR(CURRENT_DATE())
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$monthlyMessages = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// عدد الموظفين
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE company_id = ?");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$totalEmployees = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// الاشتراك الحالي
$stmt = $conn->prepare("
    SELECT p.name, p.monthly_limit, s.end_date
    FROM subscriptions s
    JOIN plans p ON s.plan_id = p.id
    WHERE s.company_id = ? AND s.status = 'active'
    LIMIT 1
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$subscription = $stmt->get_result()->fetch_assoc();

$planName = $subscription['name'] ?? 'No Plan';
$limit    = $subscription['monthly_limit'] ?? 0;
$endDate  = $subscription['end_date'] ?? null;

$usagePercent = ($limit > 0) ? min(100, round(($monthlyMessages / $limit) * 100)) : 0;

// آخر 5 رسائل
$stmt = $conn->prepare("
    SELECT m.recipient, m.status, m.sent_at
    FROM messages m
    JOIN users u ON m.user_id = u.id
    WHERE u.company_id = ?
    ORDER BY m.sent_at DESC
    LIMIT 5
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$recentMessages = $stmt->get_result();

include("../layouts/header.php");
?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">

    <div class="col-md-3 col-sm-6">
        <div class="kpi-card kpi-purple fade-in">
            <div class="kpi-bg"></div>
            <div class="kpi-icon"><i class="bi bi-chat-dots"></i></div>
            <div class="kpi-value"><?php echo number_format($totalMessages); ?></div>
            <div class="kpi-label">Total Messages</div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="kpi-card kpi-blue fade-in" style="animation-delay:.05s">
            <div class="kpi-bg"></div>
            <div class="kpi-icon"><i class="bi bi-calendar-check"></i></div>
            <div class="kpi-value"><?php echo number_format($monthlyMessages); ?></div>
            <div class="kpi-label">This Month</div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="kpi-card kpi-green fade-in" style="animation-delay:.1s">
            <div class="kpi-bg"></div>
            <div class="kpi-icon"><i class="bi bi-people"></i></div>
            <div class="kpi-value"><?php echo $totalEmployees; ?></div>
            <div class="kpi-label">Employees</div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="kpi-card kpi-orange fade-in" style="animation-delay:.15s">
            <div class="kpi-bg"></div>
            <div class="kpi-icon"><i class="bi bi-lightning-charge"></i></div>
            <div class="kpi-value" style="font-size:18px;"><?php echo htmlspecialchars($planName); ?></div>
            <div class="kpi-label">
                <?php echo $endDate ? 'Until ' . date('M d, Y', strtotime($endDate)) : 'Current Plan'; ?>
            </div>
        </div>
    </div>

</div>

<!-- Usage + Recent -->
<div class="row g-3">

    <!-- Usage Bar -->
    <div class="col-md-5">
        <div class="card p-4 h-100">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="fw-bold mb-0">Monthly Usage</h6>
                <a href="upgrade.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-lightning-charge me-1"></i> Upgrade
                </a>
            </div>

            <?php if ($limit > 0): ?>
                <div class="d-flex justify-content-between mb-2" style="font-size:13px; color:#64748b;">
                    <span><?php echo number_format($monthlyMessages); ?> used</span>
                    <span><?php echo number_format($limit); ?> limit</span>
                </div>
                <div class="progress mb-2">
                    <div class="progress-bar <?php echo $usagePercent >= 90 ? 'danger' : ''; ?>"
                         style="width:<?php echo $usagePercent; ?>%"></div>
                </div>
                <div class="d-flex justify-content-between" style="font-size:12px; color:#94a3b8;">
                    <span><?php echo $usagePercent; ?>% used</span>
                    <span><?php echo number_format($limit - $monthlyMessages); ?> remaining</span>
                </div>
                <?php if ($usagePercent >= 90): ?>
                    <div class="alert alert-warning mt-3 mb-0 py-2" style="font-size:12.5px;">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        You're close to your monthly limit!
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-4" style="color:#94a3b8;">
                    <i class="bi bi-lightning-charge" style="font-size:32px; display:block; margin-bottom:8px;"></i>
                    No active subscription
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="col-md-7">
        <div class="card h-100">
            <div class="card-header-custom d-flex align-items-center justify-content-between pb-3">
                <span>Recent Activity</span>
                <a href="send.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-send me-1"></i> Send New
                </a>
            </div>

            <div class="px-3 pb-3">
                <?php if ($recentMessages->num_rows > 0): ?>
                    <table class="table-custom w-100">
                        <thead>
                            <tr>
                                <th>Recipient</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($row = $recentMessages->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <i class="bi bi-phone me-1 text-muted"></i>
                                    <?php echo htmlspecialchars($row['recipient']); ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] == 'sent'): ?>
                                        <span class="badge-success">✓ Sent</span>
                                    <?php else: ?>
                                        <span class="badge-danger">✗ Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color:#94a3b8; font-size:12.5px;">
                                    <?php echo date('M d, H:i', strtotime($row['sent_at'])); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="text-center py-5" style="color:#94a3b8;">
                        <i class="bi bi-inbox" style="font-size:36px; display:block; margin-bottom:8px;"></i>
                        No messages yet
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<?php include("../layouts/footer.php"); ?>
