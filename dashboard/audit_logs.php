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

// Pagination setup
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 30;
$offset = ($page - 1) * $limit;

// Count total logs
$cStmt = $conn->prepare("SELECT COUNT(*) FROM company_audit_logs WHERE company_id = ?");
$cStmt->bind_param("i", $companyId);
$cStmt->execute();
$totalLogs = 0;
$cStmt->bind_result($totalLogs);
$cStmt->fetch();
$cStmt->close();

$totalPages = max(1, ceil($totalLogs / $limit));

// Fetch logs
$stmt = $conn->prepare("
    SELECT al.*, u.name AS employee_name, u.email AS employee_email
    FROM company_audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    WHERE al.company_id = ?
    ORDER BY al.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bind_param("iii", $companyId, $limit, $offset);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include("../layouts/header.php");
?>

<div class="card p-4 shadow-sm border-0">
    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-shield-check text-primary me-2"></i>Security & Action Audit Logs</h5>
    
    <?php if (empty($logs)): ?>
        <div class="text-center py-5 text-muted">
            <i class="bi bi-clock-history" style="font-size:40px; display:block;" class="text-secondary"></i>
            <p class="mt-2" style="font-size:14px;">No audit events recorded yet.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle" style="font-size:13px;">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Details / Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $action = $log['action_type'];
                        $badgeClass = 'bg-secondary';
                        if (strpos($action, 'create') !== false || strpos($action, 'schedule') !== false) {
                            $badgeClass = 'bg-success';
                        } elseif (strpos($action, 'delete') !== false) {
                            $badgeClass = 'bg-danger';
                        } elseif (strpos($action, 'update') !== false || strpos($action, 'status') !== false) {
                            $badgeClass = 'bg-warning text-dark';
                        }
                        ?>
                        <tr>
                            <td class="text-muted"><?php echo date('M d, Y H:i:s', strtotime($log['created_at'])); ?></td>
                            <td>
                                <?php if ($log['user_id'] === null): ?>
                                    <span class="text-secondary fw-semibold">System/Web Trigger</span>
                                <?php else: ?>
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($log['employee_name'] ?? 'Admin'); ?></div>
                                    <small class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($log['employee_email'] ?? ''); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(ucfirst($action)); ?></span></td>
                            <td class="text-dark"><?php echo htmlspecialchars($log['description']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination Controls -->
        <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination pagination-sm justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>">Previous</a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include("../layouts/footer.php"); ?>
