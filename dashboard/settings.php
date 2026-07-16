<?php
/**
 * WA Manager — Consolidated System Settings
 * File: dashboard/settings.php
 */
session_start();
require_once("../config/db.php");
require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');
require_once(__DIR__ . '/../core/Company/CompanyRepository.php');
require_once(__DIR__ . '/../core/Employee/EmployeeRepository.php');
require_once(__DIR__ . '/../core/WhatsAppNumber/WhatsAppNumberRepository.php');

use Core\Auth\CsrfHelper;
use Core\Company\CompanyRepository;
use Core\Employee\EmployeeRepository;
use Core\WhatsAppNumber\WhatsAppNumberRepository;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$companyId = (int)$_SESSION['company_id'];
$role = $_SESSION['role'];
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

$companyRepo = new CompanyRepository($conn);
$empRepo = new EmployeeRepository($conn);
$waNumRepo = new WhatsAppNumberRepository($conn);

// Fetch company details
$company = $companyRepo->getById($companyId);
if (!$company) {
    die("Company not found.");
}

// Fetch employees
$employeesList = $empRepo->getEmployees($companyId);

// ── 1. Handle Company Profile Update POST ──
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'company_profile')) {
        die("Invalid CSRF Token.");
    }
    
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $passwordHash = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    $companyRepo->updateProfile($companyId, $name, $email, $passwordHash);
    $_SESSION['company_name'] = $name;
    
    require_once(__DIR__ . '/../core/Services/AuditLogService.php');
    $audit = new \Core\Services\AuditLogService($conn);
    $audit->log($companyId, $userId, 'update_company_profile', "Updated company settings: Name = $name, Email = $email");

    $success = "✅ Company profile updated successfully!";
    $company = $companyRepo->getById($companyId);
}

// ── 2. Handle Link WhatsApp Number POST ──
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'link_whatsapp') {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'whatsapp_numbers')) {
        die("Invalid CSRF Token.");
    }

    $targetUserId = (int)$_POST['user_id'];
    $phoneNumberId = trim($_POST['phone_number_id']);
    $existing = $waNumRepo->getPhoneNumberIdByUserId($targetUserId);

    if ($existing !== null) {
        $error = "⚠ This employee already has a WhatsApp number linked.";
    } else {
        $empRepo->upsertPhoneNumber($targetUserId, $phoneNumberId);
        
        require_once(__DIR__ . '/../core/Services/AuditLogService.php');
        $audit = new \Core\Services\AuditLogService($conn);
        $audit->log($companyId, $userId, 'link_whatsapp', "Linked Phone ID $phoneNumberId to employee ID $targetUserId");

        $success = "✅ WhatsApp number linked successfully!";
    }
}

// ── 3. Handle Delete/Unlink WhatsApp Number POST ──
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'unlink_whatsapp') {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'whatsapp_numbers')) {
        die("Invalid CSRF Token.");
    }

    $targetUserId = (int)$_POST['user_id'];
    if ($targetUserId > 0) {
        $empRepo->deletePhoneNumbers($targetUserId);
        
        require_once(__DIR__ . '/../core/Services/AuditLogService.php');
        $audit = new \Core\Services\AuditLogService($conn);
        $audit->log($companyId, $userId, 'unlink_whatsapp', "Unlinked WhatsApp number from employee ID $targetUserId");

        $success = "✅ WhatsApp number unlinked successfully!";
    }
}

// ── 4. Handle Customer Routing Settings Update POST ──
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_routing') {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'customer_routing')) {
        die("Invalid CSRF Token.");
    }
    
    if ($role !== 'admin') {
        die("Unauthorized");
    }
    
    $mode = trim($_POST['auto_assignment_mode'] ?? 'manual');
    $conn->query("UPDATE companies SET auto_assignment_mode = '" . $conn->real_escape_string($mode) . "' WHERE id = $companyId");
    
    require_once(__DIR__ . '/../core/Services/AuditLogService.php');
    $audit = new \Core\Services\AuditLogService($conn);
    $audit->log($companyId, $userId, 'update_customer_routing', "Updated automatic customer routing strategy: Mode = $mode");
    
    $success = "✅ Customer routing strategy settings updated successfully!";
    $company = $companyRepo->getById($companyId);
}

// Fetch currently linked WhatsApp numbers
$linkedNumbers = [];
$stmt = $conn->prepare("
    SELECT wn.*, u.name AS employee_name 
    FROM whatsapp_numbers wn
    JOIN users u ON wn.user_id = u.id
    WHERE u.company_id = ?
");
$stmt->bind_param("i", $companyId);
$stmt->execute();
$linkedNumbers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include("../layouts/header.php");
?>

<?php if (isset($success)): ?>
    <div class="alert alert-success py-2 mb-3" style="font-size:13.5px;"><i class="bi bi-check-circle me-1"></i><?php echo $success; ?></div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:13.5px;"><i class="bi bi-exclamation-circle me-1"></i><?php echo $error; ?></div>
<?php endif; ?>

<div class="row g-4">
    <!-- Tabs Sidebar Navigation -->
    <div class="col-md-3">
        <div class="card p-3 shadow-sm border-0">
            <div class="nav flex-column nav-pills" id="settings-tabs" role="tablist" aria-orientation="vertical">
                <button class="nav-link active text-start fw-semibold" id="tab-company-btn" data-bs-toggle="pill" data-bs-target="#tab-company" type="button" role="tab"><i class="bi bi-building me-2"></i>Company Details</button>
                <button class="nav-link text-start fw-semibold" id="tab-whatsapp-btn" data-bs-toggle="pill" data-bs-target="#tab-whatsapp" type="button" role="tab"><i class="bi bi-phone me-2"></i>WhatsApp Integration</button>
                <button class="nav-link text-start fw-semibold" id="tab-notifications-btn" data-bs-toggle="pill" data-bs-target="#tab-notifications" type="button" role="tab"><i class="bi bi-bell me-2"></i>Notifications</button>
                <button class="nav-link text-start fw-semibold" id="tab-roles-btn" data-bs-toggle="pill" data-bs-target="#tab-roles" type="button" role="tab"><i class="bi bi-shield-lock me-2"></i>Roles & Permissions</button>
                <button class="nav-link text-start fw-semibold" id="tab-routing-btn" data-bs-toggle="pill" data-bs-target="#tab-routing" type="button" role="tab"><i class="bi bi-shuffle me-2"></i>Customer Routing</button>
            </div>
        </div>
    </div>

    <!-- Tabs Content Window -->
    <div class="col-md-9">
        <div class="card p-4 shadow-sm border-0">
            <div class="tab-content" id="settings-tabs-content">
                
                <!-- 1. Company Profile Tab -->
                <div class="tab-pane fade show active" id="tab-company" role="tabpanel">
                    <h5 class="fw-bold text-dark mb-4">Company Details Settings</h5>
                    <?php if ($role !== 'admin'): ?>
                        <div class="alert alert-warning py-2" style="font-size:12.5px;">Editing company profile settings requires Admin role.</div>
                    <?php endif; ?>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('company_profile'); ?>">
                        
                        <div class="mb-3">
                            <label class="form-label text-secondary fw-semibold mb-1" style="font-size:12.5px;">Company Name</label>
                            <input type="text" name="name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($company['name'] ?? ''); ?>" required <?php echo $role !== 'admin' ? 'disabled' : ''; ?>>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-secondary fw-semibold mb-1" style="font-size:12.5px;">Billing/Contact Email</label>
                            <input type="email" name="email" class="form-control form-control-sm" value="<?php echo htmlspecialchars($company['email'] ?? ''); ?>" required <?php echo $role !== 'admin' ? 'disabled' : ''; ?>>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-secondary fw-semibold mb-1" style="font-size:12.5px;">Change Account Password</label>
                            <input type="password" name="password" placeholder="Leave blank to retain current password" class="form-control form-control-sm" <?php echo $role !== 'admin' ? 'disabled' : ''; ?>>
                        </div>

                        <?php if ($role === 'admin'): ?>
                            <button type="submit" class="btn btn-primary btn-sm py-2 px-4 fw-semibold"><i class="bi bi-save me-1"></i> Update Company Profile</button>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- 2. WhatsApp Numbers Tab -->
                <div class="tab-pane fade" id="tab-whatsapp" role="tabpanel">
                    <h5 class="fw-bold text-dark mb-4">WhatsApp Cloud API Numbers</h5>
                    
                    <?php if ($role === 'admin'): ?>
                        <div class="card p-3 bg-light border-0 mb-4">
                            <h6 class="fw-bold text-secondary mb-3" style="font-size:13px;">Link Number to Employee</h6>
                            <form method="POST" action="">
                                <input type="hidden" name="action" value="link_whatsapp">
                                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('whatsapp_numbers'); ?>">
                                
                                <div class="row g-2">
                                    <div class="col-md-5">
                                        <select name="user_id" class="form-select form-select-sm" required>
                                            <option value="">Select Employee...</option>
                                            <?php foreach($employeesList as $emp): ?>
                                                <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" name="phone_number_id" placeholder="Phone Number ID (e.g. 1165715256628007)" required class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg"></i> Link</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <h6 class="fw-bold text-dark mb-3" style="font-size:13.5px;">Linked API Phone IDs</h6>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle" style="font-size:12.5px;">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>WhatsApp Phone ID</th>
                                    <?php if ($role === 'admin'): ?>
                                        <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($linkedNumbers)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-muted py-3">No numbers linked.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($linkedNumbers as $num): ?>
                                        <tr>
                                            <td class="fw-bold"><?php echo htmlspecialchars($num['employee_name']); ?></td>
                                            <td><code><?php echo htmlspecialchars($num['phone_number_id']); ?></code></td>
                                            <?php if ($role === 'admin'): ?>
                                                <td>
                                                    <form method="POST" action="" onsubmit="return confirm('Unlink this number?');" style="margin:0;">
                                                        <input type="hidden" name="action" value="unlink_whatsapp">
                                                        <input type="hidden" name="user_id" value="<?php echo $num['user_id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('whatsapp_numbers'); ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:11px;">Unlink</button>
                                                    </form>
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 3. Notification Muting Preferences Tab -->
                <div class="tab-pane fade" id="tab-notifications" role="tabpanel">
                    <h5 class="fw-bold text-dark mb-4">Notification Preferences</h5>
                    <p class="text-secondary small">These preferences apply specifically to your browser and device.</p>
                    
                    <div class="mb-4 form-check form-switch p-3 bg-light rounded">
                        <input class="form-check-input ms-0 me-2" type="checkbox" id="muteAudioCheckbox" onchange="toggleMuteAudio(this.checked)">
                        <label class="form-check-label fw-bold text-dark" for="muteAudioCheckbox">Mute audio alert double-beeps on new incoming messages</label>
                        <div class="form-text mt-1 text-muted" style="font-size:11px;">Toggle this to silence the notification chime. Toast alerts will still be shown.</div>
                    </div>
                </div>

                <!-- 4. System Roles & Access Permissions Tab -->
                <div class="tab-pane fade" id="tab-roles" role="tabpanel">
                    <h5 class="fw-bold text-dark mb-4">Role Privileges Matrix</h5>
                    <p class="text-secondary small mb-4">Your current role: <strong><?php echo ucfirst($role); ?></strong></p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card p-3 border-start border-primary border-4 bg-light">
                                <h6 class="fw-bold text-primary mb-1">Company Administrator</h6>
                                <p class="mb-0 text-secondary" style="font-size:12.5px;">Has unrestricted access to configure WhatsApp phone links, add/remove employee members, review global audit log history, edit company billing profiles, view global performance stats, and access live chats.</p>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card p-3 border-start border-secondary border-4 bg-light">
                                <h6 class="fw-bold text-secondary mb-1">Support Employee Member</h6>
                                <p class="mb-0 text-secondary" style="font-size:12.5px;">Allowed to send and receive customer messages within Live Chat, toggle conversation statuses, manage assignment filters, attach labels/tags, log internal notes, review internal team chats, and manage browser notification settings.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 5. Customer Routing Tab -->
                <div class="tab-pane fade" id="tab-routing" role="tabpanel">
                    <h5 class="fw-bold text-dark mb-4">Automatic Customer Routing Settings</h5>
                    <p class="text-secondary small mb-4">Define how new incoming WhatsApp chats from unknown contacts are routed to employee agents.</p>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_routing">
                        <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customer_routing'); ?>">
                        
                        <div class="mb-4">
                            <label class="form-label text-secondary fw-semibold mb-1" style="font-size:12.5px;">Auto Assignment Strategy Mode</label>
                            <select name="auto_assignment_mode" class="form-select form-select-sm" required <?php echo $role !== 'admin' ? 'disabled' : ''; ?>>
                                <option value="manual" <?php echo ($company['auto_assignment_mode'] ?? 'manual') === 'manual' ? 'selected' : ''; ?>>Manual Assignment Queue</option>
                                <option value="round_robin" <?php echo ($company['auto_assignment_mode'] ?? 'manual') === 'round_robin' ? 'selected' : ''; ?>>Round Robin (Equally distribute new contacts)</option>
                                <option value="least_loaded" <?php echo ($company['auto_assignment_mode'] ?? 'manual') === 'least_loaded' ? 'selected' : ''; ?>>Least Loaded Employee (Assign to lowest count agent)</option>
                                <option value="random" <?php echo ($company['auto_assignment_mode'] ?? 'manual') === 'random' ? 'selected' : ''; ?>>Random Employee (Select any active employee)</option>
                            </select>
                        </div>
                        
                        <?php if ($role === 'admin'): ?>
                            <button type="submit" class="btn btn-primary btn-sm py-2 px-4 fw-semibold"><i class="bi bi-save me-1"></i> Save Routing Settings</button>
                        <?php endif; ?>
                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Sync notifications checkbox with local storage
    const isMuted = localStorage.getItem('muteAudioNotifications') === '1';
    const checkbox = document.getElementById('muteAudioCheckbox');
    if (checkbox) {
        checkbox.checked = isMuted;
    }
});

function toggleMuteAudio(checked) {
    localStorage.setItem('muteAudioNotifications', checked ? '1' : '0');
}
</script>

<?php include("../layouts/footer.php"); ?>
