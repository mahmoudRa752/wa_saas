<?php
/**
 * WA Manager — Employee Customer Assignment Inbox
 * File: dashboard/customer_inbox.php
 */
session_start();
require_once("../config/db.php");
require_once("../vendor/autoload.php");
require_once(__DIR__ . '/../core/TenantContext.php');
require_once(__DIR__ . '/../core/Customer/Customer.php');
require_once(__DIR__ . '/../core/Customer/CustomerRepository.php');
require_once(__DIR__ . '/../core/Customer/CustomerService.php');
require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');

use Core\TenantContext;
use Core\Customer\CustomerRepository;
use Core\Customer\CustomerService;
use Core\Auth\CsrfHelper;

if (!isset($_SESSION['company_id']) || $_SESSION['role'] !== 'employee') {
    header("Location: ../auth/login.php");
    exit;
}

$ctx = TenantContext::fromSession();
$employeeId = (int)$_SESSION['user_id'];
$companyId = $ctx->companyId;

$customerRepo = new CustomerRepository($conn);
$customerService = new CustomerService($customerRepo);

$success = null;
$error = null;

// Handle accept/reject POST dispatches
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'employee_inbox')) {
        $error = "✗ Invalid security token. Action cancelled.";
    } else {
        $action = $_POST['action'];
        $ids = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
        if (empty($ids) && !empty($_POST['customer_id'])) {
            $ids = [(int)$_POST['customer_id']];
        }

        if (empty($ids)) {
            $error = "✗ Please select at least one customer.";
        } else {
            if ($action === 'accept') {
                if ($customerService->acceptAssignments($ctx, $employeeId, $ids)) {
                    $success = "✓ Accepted " . count($ids) . " customer assignments successfully.";
                } else {
                    $error = "✗ Failed to accept customer assignments.";
                }
            } elseif ($action === 'reject') {
                if ($customerService->rejectAssignments($ctx, $employeeId, $ids)) {
                    $success = "✓ Rejected " . count($ids) . " customer assignments. They have been returned to the admin queue.";
                } else {
                    $error = "✗ Failed to reject customer assignments.";
                }
            }
        }
    }
}

// Fetch pending assignments for this employee
$stmt = $conn->prepare("
    SELECT c.*, u.name as assigner_name 
    FROM customers c
    LEFT JOIN users u ON c.assigned_by = u.id
    WHERE c.company_id = ? AND c.assigned_to = ? AND c.assignment_status = 'pending'
    ORDER BY c.assigned_at DESC
");
$stmt->bind_param("ii", $companyId, $employeeId);
$stmt->execute();
$result = $stmt->get_result();
$pendingCustomers = [];
while ($row = $result->fetch_assoc()) {
    $pendingCustomers[] = $row;
}
$stmt->close();

include("../layouts/header.php");
?>

<?php if ($success): ?>
    <div class="alert alert-success py-2 mb-3" style="font-size:13.5px;"><i class="bi bi-check-circle-fill me-1"></i><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:13.5px;"><i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card p-3 shadow-sm border-0 mb-4 bg-light">
    <h5 class="fw-bold text-dark mb-1"><i class="bi bi-inbox-fill text-primary me-2"></i>My Assignment Inbox</h5>
    <small class="text-secondary">Review and accept or reject new customer assignments delegated to you by administrators.</small>
</div>

<form method="POST" id="inbox-bulk-form">
    <input type="hidden" name="action" id="inbox-action" value="">
    <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('employee_inbox'); ?>">

    <!-- Bulk Actions toolbar -->
    <div id="inbox-toolbar" class="alert alert-dark p-3 shadow border-0 d-none justify-content-between align-items-center mb-3">
        <span class="text-white">Selected: <strong id="selected-inbox-count">0</strong> assignments.</span>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-success btn-sm fw-semibold" onclick="triggerInboxBulk('accept')"><i class="bi bi-check-lg me-1"></i> Accept Selected</button>
            <button type="button" class="btn btn-danger btn-sm fw-semibold" onclick="triggerInboxBulk('reject')"><i class="bi bi-x-lg me-1"></i> Reject Selected</button>
        </div>
    </div>

    <div class="card p-3 shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-sm" style="font-size:12.5px;">
                <thead>
                    <tr>
                        <th width="30"><input type="checkbox" id="check-all-inbox" class="form-check-input"></th>
                        <th>Mobile</th>
                        <th>Arabic Name</th>
                        <th>English Name</th>
                        <th>Project</th>
                        <th>Program</th>
                        <th>Assigned By</th>
                        <th>Assigned At</th>
                        <th width="150" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pendingCustomers)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">Your inbox is empty. No pending customer assignments.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pendingCustomers as $cust): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_ids[]" value="<?php echo $cust['id']; ?>" class="form-check-input row-checkbox-inbox"></td>
                                <td class="fw-bold"><code><?php echo htmlspecialchars($cust['mobile']); ?></code></td>
                                <td><?php echo htmlspecialchars($cust['full_name_ar'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($cust['full_name_en'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($cust['project_name'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($cust['program_name'] ?? '-'); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($cust['assigner_name'] ?? 'Admin'); ?></span></td>
                                <td><small class="text-secondary"><?php echo date('Y-m-d H:i', strtotime($cust['assigned_at'])); ?></small></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-1">
                                        <button type="button" class="btn btn-success btn-xs py-0 px-2" style="font-size:11px;" onclick="inboxSingleAction(<?php echo $cust['id']; ?>, 'accept')"><i class="bi bi-check-lg me-1"></i>Accept</button>
                                        <button type="button" class="btn btn-danger btn-xs py-0 px-2" style="font-size:11px;" onclick="inboxSingleAction(<?php echo $cust['id']; ?>, 'reject')"><i class="bi bi-x-lg me-1"></i>Reject</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>

<script>
const selectedInboxIds = new Set();

document.addEventListener('DOMContentLoaded', () => {
    const checkAll = document.getElementById('check-all-inbox');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox-inbox');

    if (checkAll) {
        checkAll.addEventListener('change', (e) => {
            const checked = e.target.checked;
            rowCheckboxes.forEach(cb => {
                cb.checked = checked;
                if (checked) {
                    selectedInboxIds.add(parseInt(cb.value));
                } else {
                    selectedInboxIds.delete(parseInt(cb.value));
                }
            });
            updateInboxToolbar();
        });
    }

    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', (e) => {
            const val = parseInt(e.target.value);
            if (e.target.checked) {
                selectedInboxIds.add(val);
            } else {
                selectedInboxIds.delete(val);
            }
            if (checkAll) {
                checkAll.checked = (selectedInboxIds.size === rowCheckboxes.length);
            }
            updateInboxToolbar();
        });
    });
});

function updateInboxToolbar() {
    const toolbar = document.getElementById('inbox-toolbar');
    const countDisplay = document.getElementById('selected-inbox-count');

    if (selectedInboxIds.size > 0) {
        toolbar.classList.remove('d-none');
        toolbar.classList.add('d-flex');
        countDisplay.innerText = selectedInboxIds.size;
    } else {
        toolbar.classList.add('d-none');
        toolbar.classList.remove('d-flex');
    }
}

function inboxSingleAction(id, act) {
    if (confirm(`Are you sure you want to ${act} this customer assignment?`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="${act}">
            <input type="hidden" name="customer_id" value="${id}">
            <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('employee_inbox'); ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function triggerInboxBulk(act) {
    if (confirm(`Are you sure you want to ${act} the selected customer assignments?`)) {
        document.getElementById('inbox-action').value = act;
        document.getElementById('inbox-bulk-form').submit();
    }
}
</script>

<?php include("../layouts/footer.php"); ?>
