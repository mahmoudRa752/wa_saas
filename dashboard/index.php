<?php
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Services/UsageService.php');
require_once(__DIR__ . '/../core/Company/CompanyRepository.php');
require_once(__DIR__ . '/../core/MessageLog/MessageLogRepository.php');

use Core\Services\UsageService;
use Core\Company\CompanyRepository;
use Core\MessageLog\MessageLogRepository;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$company_id = $_SESSION['company_id'];
$usageService = new UsageService($conn);
$companyRepo = new CompanyRepository($conn);
$msgLogRepo = new MessageLogRepository($conn);

// Standard metrics
$totalMessages = $msgLogRepo->getTotalMessagesForCompany($company_id);
$usageSummary = $usageService->getSubscriptionUsageSummary($company_id);
$monthlyMessages = $usageSummary['monthlyMessages'];
$totalEmployees = $companyRepo->getTotalEmployees($company_id);
$planName = $usageSummary['planName'];
$limit    = $usageSummary['limit'];
$endDate  = $usageSummary['endDate'];
$usagePercent = $usageSummary['usagePercent'];

// Recent activity
$recentMessagesList = $msgLogRepo->getRecentMessagesForCompany($company_id, 5);

// CRM dashboard metrics
$role = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];

if ($role === 'admin') {
    $resCust = $conn->query("SELECT COUNT(*) FROM customers WHERE company_id = $company_id");
    $totalCustomers = $resCust ? (int)($resCust->fetch_row()[0] ?? 0) : 0;

    $resAssigned = $conn->query("SELECT COUNT(*) FROM customers WHERE company_id = $company_id AND assigned_to IS NOT NULL AND assignment_status = 'accepted'");
    $assignedCustomers = $resAssigned ? (int)($resAssigned->fetch_row()[0] ?? 0) : 0;

    $resUnassigned = $conn->query("SELECT COUNT(*) FROM customers WHERE company_id = $company_id AND (assigned_to IS NULL OR assignment_status = 'unassigned')");
    $unassignedCustomers = $resUnassigned ? (int)($resUnassigned->fetch_row()[0] ?? 0) : 0;

    $resPending = $conn->query("SELECT COUNT(*) FROM customers WHERE company_id = $company_id AND assignment_status = 'pending'");
    $pendingCustomersCount = $resPending ? (int)($resPending->fetch_row()[0] ?? 0) : 0;
} else {
    $resMyCust = $conn->query("SELECT COUNT(*) FROM customers WHERE company_id = $company_id AND assigned_to = $userId AND assignment_status = 'accepted'");
    $myCustomers = $resMyCust ? (int)($resMyCust->fetch_row()[0] ?? 0) : 0;

    $resNewAss = $conn->query("SELECT COUNT(*) FROM customers WHERE company_id = $company_id AND assigned_to = $userId AND assignment_status = 'pending'");
    $newAssignments = $resNewAss ? (int)($resNewAss->fetch_row()[0] ?? 0) : 0;

    $resOpenConv = $conn->query("SELECT COUNT(*) FROM conversations WHERE company_id = $company_id AND user_id = $userId AND status = 'open'");
    $openConversations = $resOpenConv ? (int)($resOpenConv->fetch_row()[0] ?? 0) : 0;

    $resClosedConv = $conn->query("SELECT COUNT(*) FROM conversations WHERE company_id = $company_id AND user_id = $userId AND status = 'closed'");
    $closedConversations = $resClosedConv ? (int)($resClosedConv->fetch_row()[0] ?? 0) : 0;
}

// ── NEW ADVANCED ANALYTICS QUERIES ──

// 1. Conversation status counts
$statusCounts = ['open' => 0, 'pending' => 0, 'closed' => 0];
$stmt = $conn->prepare("SELECT status, COUNT(*) as count FROM conversations WHERE company_id = ? GROUP BY status");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($res as $row) {
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = (int) $row['count'];
    }
}
$stmt->close();
$totalConversations = array_sum($statusCounts);

// 2. Sent vs Failed messages over the last 7 days
$dailyStats = [];
for ($i = 6; $i >= 0; $i--) {
    $dateStr = date('Y-m-d', strtotime("-$i days"));
    $dailyStats[$dateStr] = ['sent' => 0, 'failed' => 0];
}

$stmt = $conn->prepare("
    SELECT DATE(m.sent_at) as date,
           SUM(CASE WHEN m.status = 'sent' THEN 1 ELSE 0 END) as sent_count,
           SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) as failed_count
    FROM messages m
    JOIN users u ON m.user_id = u.id
    WHERE u.company_id = ? AND m.sent_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 6 DAY)
    GROUP BY DATE(m.sent_at)
    ORDER BY DATE(m.sent_at) ASC
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($res as $row) {
    if (isset($dailyStats[$row['date']])) {
        $dailyStats[$row['date']]['sent'] = (int) $row['sent_count'];
        $dailyStats[$row['date']]['failed'] = (int) $row['failed_count'];
    }
}
$stmt->close();

$chartDates = array_keys($dailyStats);
$chartSent = array_column($dailyStats, 'sent');
$chartFailed = array_column($dailyStats, 'failed');

// 3. Employee message volumes (productivity check)
$stmt = $conn->prepare("
    SELECT u.name, COUNT(m.id) as message_count
    FROM users u
    LEFT JOIN messages m ON u.id = m.user_id
    WHERE u.company_id = ?
    GROUP BY u.id
    ORDER BY message_count DESC
    LIMIT 6
");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$empStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$empNames = array_column($empStats, 'name');
$empMessageCounts = array_column($empStats, 'message_count');

include("../layouts/header.php");
?>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="kpi-card kpi-purple fade-in">
            <div class="kpi-bg"></div>
            <div class="kpi-icon"><i class="bi bi-chat-dots"></i></div>
            <div class="kpi-value"><?php echo number_format($totalMessages); ?></div>
            <div class="kpi-label">Total Messages Sent</div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="kpi-card kpi-blue fade-in" style="animation-delay:.05s">
            <div class="kpi-bg"></div>
            <div class="kpi-icon"><i class="bi bi-chat-left-text"></i></div>
            <div class="kpi-value"><?php echo number_format($totalConversations); ?></div>
            <div class="kpi-label">Conversations (<?php echo $statusCounts['open']; ?> Open)</div>
        </div>
    </div>

    <div class="col-md-3 col-sm-6">
        <div class="kpi-card kpi-green fade-in" style="animation-delay:.1s">
            <div class="kpi-bg"></div>
            <div class="kpi-icon"><i class="bi bi-people"></i></div>
            <div class="kpi-value"><?php echo $totalEmployees; ?></div>
            <div class="kpi-label">Active Employees</div>
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

<!-- CRM KPI Cards -->
<div class="row g-3 mb-4">
    <?php if ($role === 'admin'): ?>
        <div class="col-md-3 col-sm-6">
            <div class="kpi-card kpi-purple fade-in">
                <div class="kpi-bg"></div>
                <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                <div class="kpi-value"><?php echo number_format($totalCustomers); ?></div>
                <div class="kpi-label">Total CRM Customers</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="kpi-card kpi-green fade-in" style="animation-delay:.05s">
                <div class="kpi-bg"></div>
                <div class="kpi-icon"><i class="bi bi-person-check-fill"></i></div>
                <div class="kpi-value"><?php echo number_format($assignedCustomers); ?></div>
                <div class="kpi-label">Assigned Customers</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="kpi-card kpi-orange fade-in" style="animation-delay:.1s">
                <div class="kpi-bg"></div>
                <div class="kpi-icon"><i class="bi bi-person-dash-fill"></i></div>
                <div class="kpi-value"><?php echo number_format($unassignedCustomers); ?></div>
                <div class="kpi-label">Unassigned Customers</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="kpi-card kpi-blue fade-in" style="animation-delay:.15s">
                <div class="kpi-bg"></div>
                <div class="kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                <div class="kpi-value"><?php echo number_format($pendingCustomersCount); ?></div>
                <div class="kpi-label">Pending Acceptance</div>
            </div>
        </div>
    <?php else: ?>
        <div class="col-md-3 col-sm-6">
            <div class="kpi-card kpi-purple fade-in">
                <div class="kpi-bg"></div>
                <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                <div class="kpi-value"><?php echo number_format($myCustomers); ?></div>
                <div class="kpi-label">My Customers</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <a href="customer_inbox.php" style="text-decoration:none;">
                <div class="kpi-card kpi-blue fade-in" style="animation-delay:.05s">
                    <div class="kpi-bg"></div>
                    <div class="kpi-icon"><i class="bi bi-inbox-fill"></i></div>
                    <div class="kpi-value"><?php echo number_format($newAssignments); ?></div>
                    <div class="kpi-label">New Assignments (Pending)</div>
                </div>
            </a>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="kpi-card kpi-green fade-in" style="animation-delay:.1s">
                <div class="kpi-bg"></div>
                <div class="kpi-icon"><i class="bi bi-chat-left-dots-fill"></i></div>
                <div class="kpi-value"><?php echo number_format($openConversations); ?></div>
                <div class="kpi-label">My Open Chats</div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="kpi-card kpi-orange fade-in" style="animation-delay:.15s">
                <div class="kpi-bg"></div>
                <div class="kpi-icon"><i class="bi bi-chat-left-x-fill"></i></div>
                <div class="kpi-value"><?php echo number_format($closedConversations); ?></div>
                <div class="kpi-label">My Closed Chats</div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <!-- Chart 1: Messages History -->
    <div class="col-md-6 col-12">
        <div class="card p-4 h-100 shadow-sm border-0">
            <h6 class="fw-bold mb-3"><i class="bi bi-graph-up me-2 text-primary"></i>Message History (Last 7 Days)</h6>
            <div style="position: relative; height: 260px; width: 100%;">
                <canvas id="messagesHistoryChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Chart 2: Status & Employees -->
    <div class="col-md-3 col-sm-6 col-12">
        <div class="card p-4 h-100 shadow-sm border-0">
            <h6 class="fw-bold mb-3"><i class="bi bi-pie-chart me-2 text-success"></i>Statuses</h6>
            <div style="position: relative; height: 180px; width: 100%;" class="d-flex justify-content-center align-items-center">
                <canvas id="statusChart"></canvas>
            </div>
            <div class="mt-3 text-center" style="font-size:12px; color:#64748b;">
                <span class="me-2"><i class="bi bi-circle-fill text-success"></i> Open</span>
                <span class="me-2"><i class="bi bi-circle-fill text-warning"></i> Pending</span>
                <span><i class="bi bi-circle-fill text-secondary"></i> Closed</span>
            </div>
        </div>
    </div>

    <!-- Chart 3: Employee Productivity -->
    <div class="col-md-3 col-sm-6 col-12">
        <div class="card p-4 h-100 shadow-sm border-0">
            <h6 class="fw-bold mb-3"><i class="bi bi-bar-chart me-2 text-warning"></i>Leaderboard</h6>
            <div style="position: relative; height: 220px; width: 100%;">
                <canvas id="employeeProductivityChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Usage + Recent -->
<div class="row g-3">
    <!-- Usage Bar -->
    <div class="col-md-5 col-12">
        <div class="card p-4 h-100 shadow-sm border-0">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="fw-bold mb-0">Monthly Limit Usage</h6>
                <a href="upgrade.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-lightning-charge me-1"></i> Upgrade
                </a>
            </div>

            <?php if ($limit > 0): ?>
                <div class="d-flex justify-content-between mb-2" style="font-size:13px; color:#64748b;">
                    <span><?php echo number_format($monthlyMessages); ?> used</span>
                    <span><?php echo number_format($limit); ?> limit</span>
                </div>
                <div class="progress mb-2" style="height:10px;">
                    <div class="progress-bar <?php echo $usagePercent >= 90 ? 'bg-danger' : 'bg-primary'; ?>"
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
    <div class="col-md-7 col-12">
        <div class="card h-100 shadow-sm border-0">
            <div class="card-header bg-white d-flex align-items-center justify-content-between pb-3 pt-4 px-4 border-0">
                <h6 class="fw-bold mb-0">Recent Outbound Activity</h6>
                <a href="send.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-send me-1"></i> Send New
                </a>
            </div>

            <div class="px-4 pb-4">
                <?php if (!empty($recentMessagesList)): ?>
                    <table class="table-custom w-100">
                        <thead>
                            <tr>
                                <th>Recipient</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentMessagesList as $row): ?>
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
                        <?php endforeach; ?>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    // 1. Messages History Chart (Line Chart)
    const ctxHistory = document.getElementById('messagesHistoryChart').getContext('2d');
    new Chart(ctxHistory, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($d) { return date('M d', strtotime($d)); }, $chartDates)); ?>,
            datasets: [
                {
                    label: 'Sent',
                    data: <?php echo json_encode($chartSent); ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Failed',
                    data: <?php echo json_encode($chartFailed); ?>,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });

    // 2. Status Chart (Doughnut Chart)
    const ctxStatus = document.getElementById('statusChart').getContext('2d');
    new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: ['Open', 'Pending', 'Closed'],
            datasets: [{
                data: [
                    <?php echo $statusCounts['open']; ?>,
                    <?php echo $statusCounts['pending']; ?>,
                    <?php echo $statusCounts['closed']; ?>
                ],
                backgroundColor: ['#10b981', '#f59e0b', '#64748b'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            }
        }
    });

    // 3. Employee Productivity Chart (Horizontal Bar Chart)
    const ctxEmp = document.getElementById('employeeProductivityChart').getContext('2d');
    new Chart(ctxEmp, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($empNames); ?>,
            datasets: [{
                label: 'Messages',
                data: <?php echo json_encode($empMessageCounts); ?>,
                backgroundColor: '#6366f1',
                borderRadius: 5
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
});
</script>

<?php include("../layouts/footer.php"); ?>
