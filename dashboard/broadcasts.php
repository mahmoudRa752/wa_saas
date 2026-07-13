<?php
session_start();
require_once("../config/db.php");
require_once("../vendor/autoload.php");
require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');
require_once(__DIR__ . '/../core/Company/CompanyRepository.php');

use Core\Auth\CsrfHelper;
use Core\Company\CompanyRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$role = $_SESSION['role'];
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Get employees list for display/checks
if ($role === 'admin') {
    $companyRepo = new CompanyRepository($conn);
    $employeesList = $companyRepo->getEmployees($companyId);
}

// ── 1. Create Campaign ──
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'create_campaign') {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'broadcast_campaigns')) {
        die("Invalid CSRF Token.");
    }

    $name = trim($_POST['campaign_name'] ?? '');
    $messageText = trim($_POST['message_text'] ?? '');
    $scheduledAt = trim($_POST['scheduled_at'] ?? '');
    $manualRecipients = trim($_POST['recipients_manual'] ?? '');

    $recipientsList = [];

    // Parse manual list
    if ($manualRecipients !== '') {
        $parts = explode(',', $manualRecipients);
        foreach ($parts as $p) {
            $num = preg_replace('/[^0-9]/', '', $p);
            if (!empty($num)) {
                $recipientsList[] = $num;
            }
        }
    }

    // Parse Excel list if uploaded
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['tmp_name'] !== '') {
        $fileTmp = $_FILES['excel_file']['tmp_name'];
        $fileName = $_FILES['excel_file']['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileExt === 'xlsx' || $fileExt === 'csv') {
            try {
                $spreadsheet = IOFactory::load($fileTmp);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray();
                foreach ($rows as $index => $row) {
                    if ($index == 0) continue; // skip header
                    $num = preg_replace('/[^0-9]/', '', $row[0] ?? '');
                    if (!empty($num)) {
                        $recipientsList[] = $num;
                    }
                }
            } catch (Exception $e) {
                $error = "Error parsing spreadsheet: " . $e->getMessage();
            }
        } else {
            $error = "Invalid spreadsheet format (use .xlsx or .csv)";
        }
    }

    $recipientsList = array_unique($recipientsList);
    $totalContacts = count($recipientsList);

    if (empty($recipientsList)) {
        $error = "No valid recipients found.";
    } elseif ($name === '' || $messageText === '' || $scheduledAt === '') {
        $error = "Please fill in all fields.";
    } elseif (!isset($error)) {
        $recipientsString = implode(',', $recipientsList);
        $creatorId = ($role === 'admin') ? (int)($_POST['user_id'] ?? $userId) : $userId;

        $stmt = $conn->prepare("
            INSERT INTO broadcast_campaigns (company_id, user_id, name, message_text, recipients, scheduled_at, total_contacts, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')
        ");
        $stmt->bind_param("iissssi", $companyId, $creatorId, $name, $messageText, $recipientsString, $scheduledAt, $totalContacts);
        if ($stmt->execute()) {
            $success = "✅ Broadcast Campaign scheduled successfully!";

            require_once(__DIR__ . '/../core/Services/AuditLogService.php');
            $auditService = new \Core\Services\AuditLogService($conn);
            $auditService->log($companyId, $userId, 'schedule_broadcast', "Scheduled broadcast campaign '$name' to $totalContacts recipients");
        } else {
            $error = "Failed to schedule campaign: " . $stmt->error;
        }
        $stmt->close();
    }
}

// ── 2. Cancel Campaign ──
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'cancel_campaign') {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'broadcast_campaigns')) {
        die("Invalid CSRF Token.");
    }

    $campaignId = (int)$_POST['campaign_id'];
    if ($campaignId > 0) {
        $stmt = $conn->prepare("DELETE FROM broadcast_campaigns WHERE id = ? AND company_id = ? AND status = 'scheduled'");
        $stmt->bind_param("ii", $campaignId, $companyId);
        if ($stmt->execute()) {
            $success = "✅ Scheduled campaign cancelled successfully!";
        }
        $stmt->close();
    }
}

// Fetch existing campaigns
$stmt = $conn->prepare("
    SELECT bc.*, u.name AS creator_name 
    FROM broadcast_campaigns bc
    LEFT JOIN users u ON bc.user_id = u.id
    WHERE bc.company_id = ?
    ORDER BY bc.created_at DESC
");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include("../layouts/header.php");
?>

<div class="row g-4">
    <!-- Schedule Broadcast Form -->
    <div class="col-md-5">
        <div class="card p-4 shadow-sm border-0">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-broadcast text-primary me-2"></i>Schedule Broadcast</h5>
            
            <?php if (isset($error)): ?>
                <div class="alert alert-danger py-2" style="font-size:13.5px;"><i class="bi bi-exclamation-circle me-1"></i><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if (isset($success)): ?>
                <div class="alert alert-success py-2" style="font-size:13.5px;"><i class="bi bi-check-circle me-1"></i><?php echo $success; ?></div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_campaign">
                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('broadcast_campaigns'); ?>">

                <?php if ($role === 'admin'): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Sender Employee Account</label>
                        <select name="user_id" class="form-select form-select-sm" required>
                            <?php foreach ($employeesList as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Campaign Name</label>
                    <input type="text" name="campaign_name" placeholder="e.g. Eid Mubarak Greetings" required class="form-control form-control-sm">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Message Body</label>
                    <textarea name="message_text" rows="5" placeholder="Write your broadcast message..." required class="form-control form-control-sm"></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Target Contacts (Excel/CSV)</label>
                    <input type="file" name="excel_file" class="form-control form-control-sm">
                    <div class="form-text" style="font-size:11px;">Upload a spreadsheet where the first column contains phone numbers.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Manual Recipients (Comma-separated)</label>
                    <input type="text" name="recipients_manual" placeholder="e.g. 966500000000, 966511111111" class="form-control form-control-sm">
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold text-secondary" style="font-size:13px;">Schedule Execution Date & Time</label>
                    <input type="datetime-local" name="scheduled_at" required class="form-control form-control-sm">
                </div>

                <button type="submit" class="btn btn-primary btn-sm w-100 py-2 fw-semibold">
                    <i class="bi bi-clock-history me-1"></i> Schedule Campaign
                </button>
            </form>
        </div>
    </div>

    <!-- Campaigns Directory/List -->
    <div class="col-md-7">
        <div class="card p-4 shadow-sm border-0 h-100">
            <h5 class="fw-bold text-dark mb-3"><i class="bi bi-list-columns-reverse text-primary me-2"></i>Broadcast Logs & Progress</h5>

            <?php if (empty($campaigns)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-broadcast" style="font-size:40px; display:block;" class="text-secondary"></i>
                    <p class="mt-2" style="font-size:14px;">No broadcast campaigns scheduled yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th>Campaign</th>
                                <th>Progress</th>
                                <th>Scheduled At</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $camp): ?>
                                <?php
                                $status = $camp['status'];
                                $badgeClass = ($status === 'completed') ? 'bg-success' : (($status === 'scheduled') ? 'bg-primary' : (($status === 'sending') ? 'bg-warning text-dark' : 'bg-danger'));
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo htmlspecialchars($camp['name']); ?></div>
                                        <small class="text-muted">By: <?php echo htmlspecialchars($camp['creator_name'] ?? 'Admin'); ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo $camp['sent_contacts']; ?> / <?php echo $camp['total_contacts']; ?></div>
                                        <div class="progress" style="height:6px; width:100px;">
                                            <div class="progress-bar" style="width: <?php echo ($camp['total_contacts'] > 0) ? ($camp['sent_contacts'] / $camp['total_contacts'] * 100) : 0; ?>%"></div>
                                        </div>
                                    </td>
                                    <td><?php echo date('M d, H:i', strtotime($camp['scheduled_at'])); ?></td>
                                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo ucfirst($status); ?></span></td>
                                    <td>
                                        <?php if ($status === 'scheduled'): ?>
                                            <form method="POST" action="" onsubmit="return confirm('Cancel this broadcast campaign?');">
                                                <input type="hidden" name="action" value="cancel_campaign">
                                                <input type="hidden" name="campaign_id" value="<?php echo $camp['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('broadcast_campaigns'); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;">Cancel</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
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

<?php include("../layouts/footer.php"); ?>
