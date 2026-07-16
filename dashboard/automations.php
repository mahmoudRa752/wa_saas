<?php
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');

use Core\Auth\CsrfHelper;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$role = $_SESSION['role'];

// ── 1. Create automation rule ──
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'create_rule') {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'automation_rules')) {
        die("Invalid CSRF Token.");
    }

    $name = trim($_POST['rule_name'] ?? '');
    $triggerType = trim($_POST['trigger_type'] ?? 'keyword');
    $keyword = trim($_POST['keyword'] ?? '');
    $replyText = trim($_POST['reply_text'] ?? '');

    if ($name === '' || $replyText === '') {
        $error = "Name and Reply text are required.";
    } elseif ($triggerType === 'keyword' && $keyword === '') {
        $error = "Keyword is required for keyword trigger rules.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO automation_rules (company_id, name, trigger_type, keyword, reply_text, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param("issss", $companyId, $name, $triggerType, $keyword, $replyText);
        if ($stmt->execute()) {
            $success = "✅ Automation Rule added successfully!";

            require_once(__DIR__ . '/../core/Services/AuditLogService.php');
            $auditService = new \Core\Services\AuditLogService($conn);
            $auditService->log($companyId, $_SESSION['user_id'] ?? null, 'create_automation', "Created auto-reply rule '$name'");
        } else {
            $error = "Failed to create rule: " . $stmt->error;
        }
        $stmt->close();
    }
}

// ── 2. Toggle rule status ──
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'toggle_rule') {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'automation_rules')) {
        die("Invalid CSRF Token.");
    }

    $ruleId = (int)$_POST['rule_id'];
    $newStatus = (int)$_POST['new_status'];
    if ($ruleId > 0) {
        $stmt = $conn->prepare("UPDATE automation_rules SET is_active = ? WHERE id = ? AND company_id = ?");
        $stmt->bind_param("iii", $newStatus, $ruleId, $companyId);
        if ($stmt->execute()) {
            $success = "✅ Automation Rule updated!";

            require_once(__DIR__ . '/../core/Services/AuditLogService.php');
            $auditService = new \Core\Services\AuditLogService($conn);
            $auditService->log($companyId, $_SESSION['user_id'] ?? null, 'toggle_automation', "Toggled auto-reply rule ID $ruleId status to " . ($newStatus ? 'active' : 'inactive'));
        }
        $stmt->close();
    }
}

// ── 3. Delete rule ──
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'delete_rule') {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'automation_rules')) {
        die("Invalid CSRF Token.");
    }

    $ruleId = (int)$_POST['rule_id'];
    if ($ruleId > 0) {
        $stmt = $conn->prepare("DELETE FROM automation_rules WHERE id = ? AND company_id = ?");
        $stmt->bind_param("ii", $ruleId, $companyId);
        if ($stmt->execute()) {
            $success = "✅ Automation Rule deleted!";
        }
        $stmt->close();
    }
}

// Fetch all automation rules
$stmt = $conn->prepare("SELECT * FROM automation_rules WHERE company_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include("../layouts/header.php");
?>

<div class="row g-4">
    <!-- Create Automation Rule Form -->
    <div class="col-md-5">
        <div class="card p-4 shadow-sm border-0">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-robot text-primary me-2"></i>New Auto-Reply Rule</h5>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger py-2" style="font-size:13.5px;"><i class="bi bi-exclamation-circle me-1"></i><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success py-2" style="font-size:13.5px;"><i class="bi bi-check-circle me-1"></i><?php echo $success; ?></div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="action" value="create_rule">
                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('automation_rules'); ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Rule Name</label>
                    <input type="text" name="rule_name" placeholder="e.g. Greeting Auto-reply" required class="form-control form-control-sm">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Trigger Type</label>
                    <select name="trigger_type" id="triggerTypeSelect" class="form-select form-select-sm" onchange="toggleKeywordField(this.value)">
                        <option value="keyword">On Keyword Match</option>
                        <option value="always">Always Reply (Out of Office / Responder)</option>
                    </select>
                </div>

                <div class="mb-3" id="keywordFieldContainer">
                    <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Trigger Keyword (Case-insensitive)</label>
                    <input type="text" name="keyword" placeholder="e.g. price" class="form-control form-control-sm">
                    <div class="form-text" style="font-size:11px;">Triggers if customer message contains this word.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Reply Message Text</label>
                    <textarea name="reply_text" rows="5" placeholder="Write your automatic reply..." required class="form-control form-control-sm"></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-sm w-100 py-2 fw-semibold">
                    <i class="bi bi-save me-1"></i> Save Automation Rule
                </button>
            </form>
        </div>
    </div>

    <!-- Active Rules List -->
    <div class="col-md-7">
        <div class="card p-4 shadow-sm border-0 h-100">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-cpu text-primary me-2"></i>Active Automation Rules</h5>

            <?php if (empty($rules)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-robot" style="font-size:40px; display:block;" class="text-secondary"></i>
                    <p class="mt-2" style="font-size:14px;">No auto-reply rules configured yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th>Rule</th>
                                <th>Trigger</th>
                                <th>Reply Content</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $rule): ?>
                                <?php
                                $isActive = (int)$rule['is_active'];
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($rule['name']); ?></div>
                                    </td>
                                    <td>
                                        <?php if ($rule['trigger_type'] === 'always'): ?>
                                            <span class="badge bg-info">Auto-Responder</span>
                                        <?php else: ?>
                                            <span class="badge bg-dark">Word: "<?php echo htmlspecialchars($rule['keyword']); ?>"</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="text-truncate" style="max-width:180px;" title="<?php echo htmlspecialchars($rule['reply_text']); ?>">
                                            <?php echo htmlspecialchars($rule['reply_text']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="toggle_rule">
                                            <input type="hidden" name="rule_id" value="<?php echo $rule['id']; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $isActive ? '0' : '1'; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('automation_rules'); ?>">
                                            <button type="submit" class="btn btn-sm <?php echo $isActive ? 'btn-success' : 'btn-secondary'; ?> py-0 px-2" style="font-size:11px;">
                                                <?php echo $isActive ? 'Active' : 'Muted'; ?>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" action="" onsubmit="return confirm('Delete this automation rule?');" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_rule">
                                            <input type="hidden" name="rule_id" value="<?php echo $rule['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('automation_rules'); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleKeywordField(type) {
    const container = document.getElementById('keywordFieldContainer');
    if (container) {
        container.style.display = (type === 'always') ? 'none' : 'block';
    }
}
</script>

<?php include("../layouts/footer.php"); ?>
