<?php
session_start();
require_once("../config/db.php");

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$role = $_SESSION['role'];

if ($role !== 'admin') {
    die("Access denied. Admin only.");
}

// Fetch performance stats
$query = "
    SELECT 
        u.id,
        u.name,
        u.email,
        (SELECT COUNT(*) FROM conversations c WHERE c.assigned_to = u.id AND c.company_id = ?) AS assigned_chats,
        (SELECT COUNT(*) FROM chat_messages m WHERE m.user_id = u.id AND m.direction = 'out') AS total_sent,
        (
            SELECT AVG(TIMESTAMPDIFF(MINUTE, prev.sent_at, next.sent_at))
            FROM chat_messages next
            JOIN chat_messages prev ON prev.conversation_id = next.conversation_id
                 AND prev.direction = 'in'
                 AND prev.sent_at < next.sent_at
            WHERE next.user_id = u.id 
              AND next.direction = 'out'
              AND next.sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ) AS avg_response_time_minutes
    FROM users u
    WHERE u.company_id = ?
    ORDER BY total_sent DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $companyId, $companyId);
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Prepare charts data
$labels = [];
$sentData = [];
$responseTimeData = [];

foreach ($employees as $emp) {
    $labels[] = $emp['name'];
    $sentData[] = (int)$emp['total_sent'];
    $responseTimeData[] = $emp['avg_response_time_minutes'] !== null ? round($emp['avg_response_time_minutes'], 1) : 0;
}

include("../layouts/header.php");
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="row g-4 mb-4">
    <!-- Performance Leaderboard Grid -->
    <div class="col-md-7">
        <div class="card p-4 shadow-sm border-0 h-100">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-trophy text-primary me-2"></i>Performance Leaderboard</h5>
            <div class="table-responsive">
                <table class="table table-sm align-middle" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th class="text-center">Assigned Chats</th>
                            <th class="text-center">Total Sent Messages</th>
                            <th class="text-center">Avg Response Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">No employees registered yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($employees as $emp): ?>
                                <?php
                                $avgTime = $emp['avg_response_time_minutes'];
                                if ($avgTime === null) {
                                    $timeStr = "N/A";
                                } elseif ($avgTime < 60) {
                                    $timeStr = round($avgTime) . " mins";
                                } else {
                                    $timeStr = round($avgTime / 60, 1) . " hours";
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($emp['name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($emp['email']); ?></small>
                                    </td>
                                    <td class="text-center fw-semibold"><?php echo $emp['assigned_chats']; ?></td>
                                    <td class="text-center fw-semibold text-primary"><?php echo $emp['total_sent']; ?></td>
                                    <td class="text-center">
                                        <?php if ($avgTime !== null && $avgTime > 60): ?>
                                            <span class="badge bg-danger"><?php echo $timeStr; ?></span>
                                        <?php elseif ($avgTime !== null): ?>
                                            <span class="badge bg-success"><?php echo $timeStr; ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- KPI Metrics -->
    <div class="col-md-5">
        <div class="card p-4 shadow-sm border-0 h-100 bg-light">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-graph-up-arrow text-primary me-2"></i>Performance Insights</h5>
            
            <div class="d-flex flex-column gap-3">
                <div class="p-3 bg-white rounded shadow-sm border-start border-primary border-4">
                    <small class="text-muted d-block uppercase fw-bold" style="font-size:10px;">Top Responder</small>
                    <span class="fw-bold fs-5 text-dark"><?php echo !empty($employees) ? htmlspecialchars($employees[0]['name']) : 'N/A'; ?></span>
                    <small class="text-muted d-block" style="font-size:12px;">With <?php echo !empty($employees) ? $employees[0]['total_sent'] : 0; ?> messages sent.</small>
                </div>

                <div class="p-3 bg-white rounded shadow-sm border-start border-success border-4">
                    <small class="text-muted d-block uppercase fw-bold" style="font-size:10px;">Avg Response (Global)</small>
                    <?php
                    $totalTime = 0;
                    $count = 0;
                    foreach ($employees as $emp) {
                        if ($emp['avg_response_time_minutes'] !== null) {
                            $totalTime += $emp['avg_response_time_minutes'];
                            $count++;
                        }
                    }
                    $globalAvg = ($count > 0) ? ($totalTime / $count) : null;
                    if ($globalAvg === null) {
                        $globalStr = "N/A";
                    } elseif ($globalAvg < 60) {
                        $globalStr = round($globalAvg) . " mins";
                    } else {
                        $globalStr = round($globalAvg / 60, 1) . " hours";
                    }
                    ?>
                    <span class="fw-bold fs-5 text-dark"><?php echo $globalStr; ?></span>
                    <small class="text-muted d-block" style="font-size:12px;">Across all tracked customer responses.</small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Messages Sent Chart -->
    <div class="col-md-6">
        <div class="card p-4 shadow-sm border-0">
            <h6 class="fw-bold text-secondary mb-3">Outgoing Messages Volume</h6>
            <div style="height: 250px;">
                <canvas id="messagesSentChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Avg Response Time Chart -->
    <div class="col-md-6">
        <div class="card p-4 shadow-sm border-0">
            <h6 class="fw-bold text-secondary mb-3">Average Response Time (Minutes)</h6>
            <div style="height: 250px;">
                <canvas id="responseTimeChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 1. Messages Sent Chart
    const sentCtx = document.getElementById('messagesSentChart');
    if (sentCtx) {
        new Chart(sentCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Messages Sent',
                    data: <?php echo json_encode($sentData); ?>,
                    backgroundColor: '#4f46e5',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    // 2. Response Time Chart
    const timeCtx = document.getElementById('responseTimeChart');
    if (timeCtx) {
        new Chart(timeCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [{
                    label: 'Response Time (mins)',
                    data: <?php echo json_encode($responseTimeData); ?>,
                    backgroundColor: '#10b981',
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }
});
</script>

<?php include("../layouts/footer.php"); ?>
