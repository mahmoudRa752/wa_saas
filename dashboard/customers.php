<?php
/**
 * WA Manager — Customer Manager (CRM) Standalone Panel
 * File: dashboard/customers.php
 */
session_start();
require_once("../config/db.php");
require_once("../vendor/autoload.php");
require_once(__DIR__ . '/../core/TenantContext.php');
require_once(__DIR__ . '/../core/Customer/Customer.php');
require_once(__DIR__ . '/../core/Customer/CustomerRepository.php');
require_once(__DIR__ . '/../core/Customer/CustomerService.php');
require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');
require_once(__DIR__ . '/../core/SavedReply/SavedReply.php');
require_once(__DIR__ . '/../core/SavedReply/SavedReplyRepository.php');
require_once(__DIR__ . '/../core/Company/CompanyRepository.php');

use Core\TenantContext;
use Core\Customer\Customer;
use Core\Customer\CustomerRepository;
use Core\Customer\CustomerService;
use Core\Auth\CsrfHelper;
use Core\SavedReply\SavedReplyRepository;
use Core\Company\CompanyRepository;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$ctx = TenantContext::fromSession();
$companyId = $ctx->companyId;
$role = $_SESSION['role'];

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId === 0 && isset($_SESSION['company_id'])) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE company_id = ? AND role = 'admin' LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['company_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            $userId = (int)$row['id'];
            $_SESSION['user_id'] = $userId;
        }
        $stmt->close();
    }
}

$isAdmin = ($role === 'admin');

$customerRepo = new CustomerRepository($conn);
$customerService = new CustomerService($customerRepo);
$savedReplyRepo = new SavedReplyRepository($conn);
$companyRepo = new CompanyRepository($conn);

// Fetch Canned replies for templates selector
$cannedReplies = $savedReplyRepo->findAll($ctx);

// Fetch employees list for selector & mapping
$employeesList = $companyRepo->getEmployees($companyId);
$employeesMap = [];
foreach ($employeesList as $emp) {
    $employeesMap[$emp['id']] = $emp['name'];
}

// Gather request filters
$filters = [
    'gender' => $_GET['gender'] ?? '',
    'nationality' => $_GET['nationality'] ?? '',
    'employer' => $_GET['employer'] ?? '',
    'project_name' => $_GET['project_name'] ?? '',
    'program_name' => $_GET['program_name'] ?? '',
    'imported_date' => $_GET['imported_date'] ?? '',
    'national_id_status' => $_GET['national_id_status'] ?? '',
    'assigned_to_user' => $_GET['assigned_to_user'] ?? '',
    'assignment_status' => $_GET['assignment_status'] ?? '',
];
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, min(100, (int)($_GET['limit'] ?? 20)));
$sortBy = $_GET['sort'] ?? 'created_at';
$sortOrder = $_GET['order'] ?? 'DESC';

// Handle POST actions
$success = null;
$error = null;
$importReport = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Validate CSRF
    $csrfToken = $_POST['csrf_token'] ?? '';
    $csrfAction = $_POST['csrf_action'] ?? 'customers_crm';
    if (!CsrfHelper::validateToken($csrfToken, $csrfAction)) {
        $error = "Access Denied: Invalid security token.";
    } else {
        $action = $_POST['action'];

        // Role restriction checks
        if (!$isAdmin && in_array($action, ['import_customers', 'delete_customer', 'assign_customers'])) {
            $error = "Access Denied: Employees are unauthorized to execute this command.";
        } else {
            if ($action === 'import_customers') {
                if (isset($_FILES['excel_file']) && $_FILES['excel_file']['tmp_name'] !== '') {
                    $report = $customerService->importFromFile($ctx, $_FILES['excel_file']['tmp_name'], $_FILES['excel_file']['name']);
                    if ($report['success']) {
                        $success = "✓ Spreadsheet imported successfully!";
                        $importReport = $report;
                    } else {
                        $error = "✗ Import failed: " . $report['error'];
                    }
                } else {
                    $error = "✗ Please select a file to upload.";
                }
            }

            elseif ($action === 'save_customer') {
                $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
                $customer = new Customer(
                    id: $id,
                    companyId: $companyId,
                    fullNameAr: trim($_POST['full_name_ar'] ?? '') ?: null,
                    fullNameEn: trim($_POST['full_name_en'] ?? '') ?: null,
                    mobile: trim($_POST['mobile']),
                    nationalId: trim($_POST['national_id'] ?? '') ?: null,
                    nationality: trim($_POST['nationality'] ?? '') ?: null,
                    gender: trim($_POST['gender'] ?? '') ?: null,
                    projectName: trim($_POST['project_name'] ?? '') ?: null,
                    programName: trim($_POST['program_name'] ?? '') ?: null,
                    employer: trim($_POST['employer'] ?? '') ?: null,
                    sourceFile: $id ? null : 'Manual Entry',
                    createdAt: null,
                    updatedAt: null
                );

                $res = $customerService->saveCustomer($ctx, $customer);
                if ($res['success']) {
                    $success = "✓ Customer saved successfully!";
                } else {
                    $error = "✗ Failed to save customer: " . $res['error'];
                }
            }

            elseif ($action === 'delete_customer') {
                $id = (int)$_POST['id'];
                if ($customerService->deleteCustomer($ctx, $id)) {
                    $success = "✓ Customer deleted successfully!";
                } else {
                    $error = "✗ Failed to delete customer.";
                }
            }

            elseif ($action === 'assign_customers') {
                $assignedTo = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : null;
                $targetType = $_POST['target_type'] ?? 'selected';
                $ids = [];

                if ($targetType === 'selected') {
                    $ids = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
                } else {
                    $ids = $customerService->getFilteredCustomerIds($ctx, $filters, $searchQuery, $role, $userId);
                }

                if (empty($ids)) {
                    $error = "✗ No customers selected for assignment.";
                } else {
                    $adminIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $assignedBy = (int)$_SESSION['user_id'];
                    if ($customerService->assignCustomers($ctx, $ids, $assignedTo, $assignedBy, $adminIp)) {
                        $success = "✓ Successfully processed assignments for " . count($ids) . " customers.";
                    } else {
                        $error = "✗ Failed to process customer assignments.";
                    }
                }
            }

            elseif ($action === 'send_whatsapp') {
                $targetType = $_POST['target_type'] ?? 'selected';
                $mobiles = [];

                if ($targetType === 'selected') {
                    $ids = isset($_POST['selected_ids']) ? array_map('intval', $_POST['selected_ids']) : [];
                    if (!empty($ids)) {
                        $inClause = implode(',', array_fill(0, count($ids), '?'));
                        $sql = "SELECT mobile FROM customers WHERE company_id = ? AND id IN ($inClause)";
                        $stmt = $conn->prepare($sql);
                        if ($stmt) {
                            $types = 'i' . str_repeat('i', count($ids));
                            $params = array_merge([$companyId], $ids);
                            $stmt->bind_param($types, ...$params);
                            $stmt->execute();
                            $res = $stmt->get_result();
                            while ($row = $res->fetch_row()) {
                                $mobiles[] = $row[0];
                            }
                            $stmt->close();
                        }
                    }
                } else {
                    $ids = $customerService->getFilteredCustomerIds($ctx, $filters, $searchQuery, $role, $userId);
                    if (!empty($ids)) {
                        $chunks = array_chunk($ids, 1000);
                        foreach ($chunks as $chunk) {
                            $inClause = implode(',', array_fill(0, count($chunk), '?'));
                            $sql = "SELECT mobile FROM customers WHERE company_id = ? AND id IN ($inClause)";
                            $stmt = $conn->prepare($sql);
                            if ($stmt) {
                                $types = 'i' . str_repeat('i', count($chunk));
                                $params = array_merge([$companyId], $chunk);
                                $stmt->bind_param($types, ...$params);
                                $stmt->execute();
                                $res = $stmt->get_result();
                                while ($row = $res->fetch_row()) {
                                    $mobiles[] = $row[0];
                                }
                                $stmt->close();
                            }
                        }
                    }
                }

                $mobiles = array_unique(array_filter($mobiles));
                $totalRecipients = count($mobiles);

                if ($totalRecipients === 0) {
                    $error = "✗ No valid recipients found.";
                } else {
                    $sendType = $_POST['send_type'] ?? 'text';
                    $messageText = trim($_POST['message_text'] ?? '');
                    $templateName = null;
                    $templateLanguage = 'en_US';

                    if ($sendType === 'template') {
                        $templateName = trim($_POST['template_name'] ?? '');
                        $templateLanguage = trim($_POST['template_language'] ?? 'en_US');
                        $messageText = "Meta Template Broadcast campaign";
                    }

                    $campaignName = "CRM Send - " . date('Y-m-d H:i');
                    $recipientsString = implode(',', $mobiles);

                    $stmt = $conn->prepare("
                        INSERT INTO broadcast_campaigns (company_id, user_id, name, message_text, recipients, scheduled_at, total_contacts, status, template_name, template_language)
                        VALUES (?, ?, ?, ?, ?, NOW(), ?, 'scheduled', ?, ?)
                    ");
                    $creatorId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
                    $stmt->bind_param("iisssisss", $companyId, $creatorId, $campaignName, $messageText, $recipientsString, $totalRecipients, $templateName, $templateLanguage);
                    if ($stmt->execute()) {
                        $success = "✓ WhatsApp Bulk Broadcast initiated successfully! Campaign processing in background.";
                        pclose(popen("start /B php " . escapeshellarg(__DIR__ . '/../cron/process_broadcasts.php'), "r"));
                    } else {
                        $error = "✗ Failed to create campaign: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Fetch lists matching filters & roles scoping
$results = $customerService->getPaginatedCustomers($ctx, $filters, $searchQuery, $page, $limit, $sortBy, $sortOrder, $role, $userId);
$customersList = $results['data'];
$totalCount = $results['total'];
$totalPages = $results['pages'];

// Fetch filter dropdown selectors dynamically
$dropdowns = $customerService->getFilterDropdowns($ctx);

include("../layouts/header.php");
?>

<?php if ($success): ?>
    <div class="alert alert-success py-2 mb-3" style="font-size:13.5px;"><i class="bi bi-check-circle-fill me-1"></i><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:13.5px;"><i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($importReport): ?>
    <div class="alert alert-info py-3 mb-3" style="font-size:13.5px;">
        <h6 class="fw-bold mb-2"><i class="bi bi-file-earmark-bar-graph me-1"></i> Customer Sheet Import Report</h6>
        <div class="row g-2 text-center text-md-start">
            <div class="col-6 col-md-2">Total Rows: <strong><?php echo $importReport['total_rows']; ?></strong></div>
            <div class="col-6 col-md-2">New Imported: <strong class="text-success"><?php echo $importReport['imported']; ?></strong></div>
            <div class="col-6 col-md-2">Updated: <strong class="text-primary"><?php echo $importReport['updated']; ?></strong></div>
            <div class="col-6 col-md-2">Skipped: <strong class="text-muted"><?php echo $importReport['skipped']; ?></strong></div>
            <div class="col-6 col-md-2">Sheet Duplicates: <strong class="text-warning"><?php echo $importReport['duplicates']; ?></strong></div>
            <div class="col-6 col-md-2">Invalid Mobiles: <strong class="text-danger"><?php echo $importReport['invalid_mobiles']; ?></strong></div>
        </div>
    </div>
<?php endif; ?>

<div class="card p-3 shadow-sm border-0 mb-4 bg-light">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-people-fill text-primary me-2"></i>Customer Manager (CRM)</h5>
            <small class="text-secondary"><?php echo $isAdmin ? 'Manage contact database sheet imports, assign records, and execute campaigns.' : 'View and manage customers assigned to your account.'; ?></small>
        </div>
        <div class="d-flex gap-2">
            <?php if ($isAdmin): ?>
                <button class="btn btn-primary btn-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal-add-customer"><i class="bi bi-plus-lg me-1"></i> Add Customer</button>
                <button class="btn btn-success btn-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal-import-customers"><i class="bi bi-file-earmark-excel me-1"></i> Import Sheet</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Search & Filters -->
<form method="GET" action="" class="card p-3 shadow-sm border-0 mb-4">
    <div class="row g-2 mb-2">
        <div class="col-md-4">
            <label class="form-label text-secondary fw-semibold small mb-1">Global Search</label>
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-light text-secondary border-end-0"><i class="bi bi-search"></i></span>
                <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search Name, Mobile, Employer, ID..." class="form-control form-control-sm border-start-0">
            </div>
        </div>
        <div class="col-md-2">
            <label class="form-label text-secondary fw-semibold small mb-1">Gender</label>
            <select name="gender" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach($dropdowns['genders'] as $val): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $filters['gender'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label text-secondary fw-semibold small mb-1">Nationality</label>
            <select name="nationality" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach($dropdowns['nationalities'] as $val): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $filters['nationality'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label text-secondary fw-semibold small mb-1">Employer</label>
            <select name="employer" class="form-select form-select-sm">
                <option value="">All</option>
                <?php foreach($dropdowns['employers'] as $val): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $filters['employer'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label text-secondary fw-semibold small mb-1">Imported Date</label>
            <input type="date" name="imported_date" value="<?php echo htmlspecialchars($filters['imported_date']); ?>" class="form-control form-control-sm">
        </div>
    </div>
    
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label text-secondary fw-semibold small mb-1">Project</label>
            <select name="project_name" class="form-select form-select-sm">
                <option value="">All Projects</option>
                <?php foreach($dropdowns['projects'] as $val): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $filters['project_name'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label text-secondary fw-semibold small mb-1">Program</label>
            <select name="program_name" class="form-select form-select-sm">
                <option value="">All Programs</option>
                <?php foreach($dropdowns['programs'] as $val): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $filters['program_name'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label text-secondary fw-semibold small mb-1">National ID Status</label>
            <select name="national_id_status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="has" <?php echo $filters['national_id_status'] === 'has' ? 'selected' : ''; ?>>Has ID/Residence</option>
                <option value="missing" <?php echo $filters['national_id_status'] === 'missing' ? 'selected' : ''; ?>>Missing ID/Residence</option>
            </select>
        </div>
        <?php if ($isAdmin): ?>
            <div class="col-md-2">
                <label class="form-label text-secondary fw-semibold small mb-1">Assigned Employee</label>
                <select name="assigned_to_user" class="form-select form-select-sm">
                    <option value="">All Employees</option>
                    <?php foreach($employeesList as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" <?php echo $filters['assigned_to_user'] == $emp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($emp['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label text-secondary fw-semibold small mb-1">Assignment Status</label>
                <select name="assignment_status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="unassigned" <?php echo $filters['assignment_status'] === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                    <option value="pending" <?php echo $filters['assignment_status'] === 'pending' ? 'selected' : ''; ?>>Pending Acceptance</option>
                    <option value="accepted" <?php echo $filters['assignment_status'] === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                    <option value="rejected" <?php echo $filters['assignment_status'] === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
        <?php endif; ?>
        <div class="<?php echo $isAdmin ? 'col-md-2' : 'col-md-6'; ?> d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold"><i class="bi bi-filter me-1"></i> Apply Filters</button>
            <a href="customers.php" class="btn btn-outline-secondary btn-sm px-3 fw-semibold"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
    </div>
</form>

<!-- Bulk Actions Banner (Floating dynamic alert) -->
<div id="bulk-actions-banner" class="alert alert-dark p-3 shadow-lg border-0 d-none justify-content-between align-items-center mb-3" style="position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); width: 92%; max-width: 1050px; z-index: 1050; border-radius: 12px; background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1);">
    <div class="d-flex align-items-center gap-2 text-white">
        <i class="bi bi-check2-square text-success fs-5"></i>
        <span>Selected: <strong id="selected-count" class="text-warning">0</strong> customers.</span>
        <button type="button" id="select-all-filtered-btn" class="btn btn-outline-light btn-xs ms-2" style="font-size:11px;">Select all <?php echo $totalCount; ?> matches</button>
        <span id="all-selected-indicator" class="badge bg-success d-none">All matches selected</span>
    </div>
    <div class="d-flex flex-wrap gap-1 align-items-center">
        <button type="button" class="btn btn-primary btn-xs fw-semibold" style="font-size:11px;" onclick="openBulkWhatsAppModal()"><i class="bi bi-whatsapp"></i> Send WhatsApp</button>
        <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-warning btn-xs fw-semibold" style="font-size:11px;" onclick="openBulkAssignModal()"><i class="bi bi-person-plus"></i> Assign Owner</button>
        <?php endif; ?>
        <button type="button" class="btn btn-info btn-xs fw-semibold text-white" style="font-size:11px;" onclick="openBulkTagsModal('add')"><i class="bi bi-tag-fill"></i> Add Tag</button>
        <button type="button" class="btn btn-outline-info btn-xs fw-semibold" style="font-size:11px;" onclick="openBulkTagsModal('remove')"><i class="bi bi-tag"></i> Remove Tag</button>
        <button type="button" class="btn btn-secondary btn-xs fw-semibold" style="font-size:11px;" onclick="openBulkNoteModal()"><i class="bi bi-journal-text"></i> Add Note</button>
        <button type="button" class="btn btn-light btn-xs fw-semibold" style="font-size:11px;" onclick="triggerExcelExport()"><i class="bi bi-download"></i> Export</button>
        <?php if ($isAdmin): ?>
            <button type="button" class="btn btn-danger btn-xs fw-semibold" style="font-size:11px;" onclick="triggerBulkDelete()"><i class="bi bi-trash"></i> Delete</button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-light btn-xs fw-semibold" style="font-size:11px;" onclick="clearSelection()"><i class="bi bi-x-lg"></i> Clear</button>
    </div>
</div>

<!-- Customers Table -->
<div class="card p-3 shadow-sm border-0 position-relative">
    <div id="table-loading-overlay" class="position-absolute top-0 start-0 w-100 h-100 d-none d-flex justify-content-center align-items-center" style="background: rgba(255,255,255,0.7); z-index: 100; border-radius:12px;">
        <div class="spinner-border text-primary" role="status"></div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="small text-secondary">
            Showing <strong class="text-dark"><?php echo count($customersList); ?></strong> of <strong class="text-dark"><?php echo $totalCount; ?></strong> customers.
        </div>
        <div>
            <?php if ($isAdmin): ?>
                <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" onclick="exportFullFiltered()"><i class="bi bi-file-earmark-arrow-down me-1"></i> Export Filtered</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle table-sm" style="font-size:12.5px;" id="crm-table">
            <thead>
                <tr>
                    <th width="30"><input type="checkbox" id="check-all" class="form-check-input"></th>
                    <th>Mobile</th>
                    <th>Arabic Name</th>
                    <th>English Name</th>
                    <th>National ID</th>
                    <th>Employer</th>
                    <th>Project</th>
                    <th>Program</th>
                    <th>Nationality</th>
                    <th>Gender</th>
                    <?php if ($isAdmin): ?>
                        <th>Assigned To</th>
                        <th>Status</th>
                    <?php endif; ?>
                    <th width="100" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customersList)): ?>
                    <tr>
                        <td colspan="<?php echo $isAdmin ? 13 : 11; ?>" class="text-center text-muted py-4">No customers found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customersList as $cust): ?>
                        <tr data-customer-id="<?php echo $cust->id; ?>">
                            <td><input type="checkbox" value="<?php echo $cust->id; ?>" class="form-check-input row-checkbox" data-mobile="<?php echo htmlspecialchars($cust->mobile); ?>"></td>
                            <td class="fw-bold"><code><?php echo htmlspecialchars($cust->mobile); ?></code></td>
                            <td><?php echo htmlspecialchars($cust->fullNameAr ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->fullNameEn ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->nationalId ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->employer ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->projectName ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->programName ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->nationality ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->gender ?? '-'); ?></td>
                            <?php if ($isAdmin): ?>
                                <td>
                                    <?php 
                                    if ($cust->assignedTo) {
                                        echo '<span class="badge bg-dark">' . htmlspecialchars($employeesMap[$cust->assignedTo] ?? 'Employee') . '</span>';
                                    } else {
                                        echo '<span class="badge bg-secondary">Unassigned</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    if ($cust->assignmentStatus === 'accepted') {
                                        echo '<span class="badge bg-success">Accepted</span>';
                                    } elseif ($cust->assignmentStatus === 'pending') {
                                        echo '<span class="badge bg-warning text-dark">Pending</span>';
                                    } elseif ($cust->assignmentStatus === 'rejected') {
                                        echo '<span class="badge bg-danger">Rejected</span>';
                                    } else {
                                        echo '<span class="badge bg-light text-dark border">Unassigned</span>';
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <button class="btn btn-outline-primary btn-xs py-0 px-2" style="font-size:11px;" onclick="viewCustomerDetails(<?php echo htmlspecialchars(json_encode($cust->toArray())); ?>)"><i class="bi bi-eye"></i></button>
                                    <button class="btn btn-outline-warning btn-xs py-0 px-2" style="font-size:11px;" onclick="openEditCustomerModal(<?php echo htmlspecialchars(json_encode($cust->toArray())); ?>)"><i class="bi bi-pencil"></i></button>
                                    <?php if ($isAdmin): ?>
                                        <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this customer?');" style="margin:0;">
                                            <input type="hidden" name="action" value="delete_customer">
                                            <input type="hidden" name="id" value="<?php echo $cust->id; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customers_crm'); ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-xs py-0 px-2" style="font-size:11px;"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls wrapper -->
    <div class="pagination-wrapper">
        <?php if ($totalPages > 1): ?>
            <nav class="d-flex justify-content-center mt-3">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                    </li>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <li class="page-item <?php echo $page === $i ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<!-- ==========================================
     MODALS
     ========================================== -->

<!-- Assign Modal -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="modal-bulk-assign" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="form-bulk-assign" onsubmit="submitBulkAssign(event)">
                <input type="hidden" name="action" value="assign_employee">
                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customers_crm'); ?>">
                <input type="hidden" name="target_type" id="assign-target-type" value="selected">
                <div id="assign-ids-container"></div>

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-person-plus text-primary me-2"></i>Bulk Assign Customers</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="font-size:13px;">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">Assignment Strategy</label>
                        <select name="assignment_strategy" id="assign-strategy" class="form-select form-select-sm" onchange="toggleAssignStrategy(this.value)">
                            <option value="manual">Manual (Choose employee below)</option>
                            <option value="round_robin">Round Robin (Distribute equally)</option>
                            <option value="least_loaded">Least Loaded Employee</option>
                            <option value="random">Random Employee Routing</option>
                        </select>
                    </div>
                    <div class="mb-3" id="wrapper-manual-assignee">
                        <label class="form-label fw-semibold text-secondary">Choose Employee Account</label>
                        <select name="assignee_id" class="form-select form-select-sm">
                            <option value="">-- Unassign (Remove Owner) --</option>
                            <?php foreach ($employeesList as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Assignment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Import Modal -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="modal-import-customers" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-file-earmark-excel text-success me-2"></i>Import Customer Spreadsheet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="font-size:13px;">
                    <input type="hidden" name="action" value="import_customers">
                    <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customers_crm'); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">Spreadsheet File (.xlsx, .xls, .csv)</label>
                        <input type="file" name="excel_file" accept=".xlsx, .xls, .csv" required class="form-control form-control-sm">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-arrow-up"></i> Upload and Import</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add / Edit Modal -->
<div class="modal fade" id="modal-add-customer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="customer-form">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark" id="customer-modal-title"><i class="bi bi-person-plus text-primary me-2"></i>Add Manual Customer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="font-size:13px;">
                    <input type="hidden" name="action" value="save_customer">
                    <input type="hidden" name="id" id="customer-id" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customers_crm'); ?>">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-semibold mb-1">Mobile Number (Required)</label>
                            <input type="text" name="mobile" id="customer-mobile" required placeholder="e.g. 966500000000" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-semibold mb-1">National ID / Residence Code</label>
                            <input type="text" name="national_id" id="customer-national-id" placeholder="e.g. 1022334455" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-semibold mb-1">Full Name (Arabic)</label>
                            <input type="text" name="full_name_ar" id="customer-name-ar" placeholder="e.g. أحمد محمد" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-semibold mb-1">Full Name (English)</label>
                            <input type="text" name="full_name_en" id="customer-name-en" placeholder="e.g. Ahmad Mohammad" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary fw-semibold mb-1">Gender</label>
                            <select name="gender" id="customer-gender" class="form-select form-select-sm">
                                <option value="">Select...</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary fw-semibold mb-1">Nationality</label>
                            <input type="text" name="nationality" id="customer-nationality" placeholder="e.g. Saudi" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary fw-semibold mb-1">Employer / Company Name</label>
                            <input type="text" name="employer" id="customer-employer" placeholder="e.g. Aramco" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-semibold mb-1">Assigned Project</label>
                            <input type="text" name="project_name" id="customer-project" placeholder="e.g. Housing Project" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-semibold mb-1">Assigned Program</label>
                            <input type="text" name="program_name" id="customer-program" placeholder="e.g. IT Diploma" class="form-control form-control-sm">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="modal-view-customer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-person-badge text-primary me-2"></i>Customer Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="font-size:13.5px;" id="details-modal-body">
                <!-- Filled dynamically via JS -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Tags Modal -->
<div class="modal fade" id="modal-bulk-tags" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="form-bulk-tags" onsubmit="submitBulkTags(event)">
                <input type="hidden" name="action" value="bulk_tags">
                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customers_crm'); ?>">
                <input type="hidden" name="target_type" id="tags-target-type" value="selected">
                <input type="hidden" name="tag_action" id="tags-action-type" value="add">
                <div id="tags-ids-container"></div>

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-tag-fill text-primary me-2"></i><span id="tags-modal-title">Bulk Add Tag</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="font-size:13.5px;">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">Tag Label Name</label>
                        <input type="text" name="tag_name" placeholder="e.g. VIP, lead" class="form-control form-control-sm" required>
                        <div class="form-text mt-1 text-muted" style="font-size:11px;">Tags help filter segments instantly. Duplicates are auto-resolved.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Apply Tag</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Note Modal -->
<div class="modal fade" id="modal-bulk-note" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="form-bulk-note" onsubmit="submitBulkNote(event)">
                <input type="hidden" name="action" value="bulk_note">
                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customers_crm'); ?>">
                <input type="hidden" name="target_type" id="note-target-type" value="selected">
                <div id="note-ids-container"></div>

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-journal-text text-primary me-2"></i>Attach Internal Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="font-size:13.5px;">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">Internal Note Content</label>
                        <textarea name="note_text" rows="4" class="form-control form-control-sm" placeholder="Write note details to save to selected profiles..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Attach Note</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- WhatsApp Broadcast Modal -->
<div class="modal fade" id="modal-bulk-whatsapp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="form-bulk-whatsapp" onsubmit="submitBulkWhatsApp(event)">
                <input type="hidden" name="action" value="send_whatsapp">
                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customers_crm'); ?>">
                <input type="hidden" name="target_type" id="whatsapp-target-type" value="selected">
                <div id="selected-ids-container"></div>
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-whatsapp text-success me-2"></i>Create WhatsApp Campaign Queue</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="font-size:13.5px;">
                    <!-- Pre-validation block -->
                    <div id="wa-validation-block" class="alert alert-light border p-3 mb-3 bg-light">
                        <div class="fw-bold mb-2 text-dark"><i class="bi bi-shield-check text-success me-1"></i> Campaign Pre-Validation Summary:</div>
                        <div class="row g-2 text-secondary small" style="font-size:12px;">
                            <div class="col-6">Total Selected Profiles: <strong id="wa-total-selected" class="text-dark">-</strong></div>
                            <div class="col-6 text-success">Estimated Messages to Send: <strong id="wa-estimated-send" class="text-success">-</strong></div>
                            <div class="col-6 text-danger">Invalid Mobile Formats (Skipped): <strong id="wa-invalid-count" class="text-danger">-</strong></div>
                            <div class="col-6 text-warning">Duplicate Contacts (Skipped): <strong id="wa-duplicate-count" class="text-warning">-</strong></div>
                            <div class="col-6 text-danger">Blacklisted / Opt-out (Skipped): <strong id="wa-blacklist-count" class="text-danger">-</strong></div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary">Campaign Identity Name</label>
                            <input type="text" name="campaign_name" placeholder="e.g. Summer Offer 2026" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-secondary">WhatsApp Sender Link Number</label>
                            <select name="sender_id" class="form-select form-select-sm" required>
                                <?php 
                                // Query Linked WhatsApp integration numbers
                                $wnStmt = $conn->prepare("
                                    SELECT wn.* FROM whatsapp_numbers wn 
                                    JOIN users u ON wn.user_id = u.id 
                                    WHERE u.company_id = ?
                                ");
                                $wnStmt->bind_param("i", $companyId);
                                $wnStmt->execute();
                                $linkedNumbers = $wnStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                $wnStmt->close();
                                
                                if (empty($linkedNumbers)) {
                                    echo '<option value="">-- No linked WhatsApp numbers found --</option>';
                                } else {
                                    foreach ($linkedNumbers as $num) {
                                        echo '<option value="' . $num['id'] . '">' . htmlspecialchars($num['display_phone_number'] ?: $num['phone_number_id']) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold text-secondary">Message Format Type</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="send_type" id="type-text" value="text" checked onchange="toggleWhatsAppSendType('text')">
                                <label class="form-check-label fw-bold text-dark" for="type-text">Free Text Message</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="send_type" id="type-template" value="template" onchange="toggleWhatsAppSendType('template')">
                                <label class="form-check-label fw-bold text-dark" for="type-template">WhatsApp Cloud Template</label>
                            </div>
                        </div>
                    </div>

                    <div id="section-free-text">
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-secondary">Insert Saved Quick Reply</label>
                            <select class="form-select form-select-sm" onchange="insertQuickReplyText(this.value)">
                                <option value="">-- Choose canned reply template --</option>
                                <?php foreach($cannedReplies as $rep): ?>
                                    <option value="<?php echo htmlspecialchars($rep->body); ?>"><?php echo htmlspecialchars($rep->title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-secondary">Message Content</label>
                            <textarea name="message_text" id="wa-message-text" rows="4" class="form-control form-control-sm" placeholder="Write your broadcast message..."></textarea>
                        </div>
                    </div>

                    <div id="section-template" class="d-none">
                        <div class="row g-2 mb-3">
                            <div class="col-md-8">
                                <label class="form-label fw-semibold text-secondary">Official Meta Template Name</label>
                                <input type="text" name="template_name" id="wa-template-name" placeholder="e.g. hello_world" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-secondary">Language Code</label>
                                <input type="text" name="template_language" id="wa-template-lang" value="en_US" placeholder="e.g. en_US" class="form-control form-control-sm">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold text-secondary">Template Variables (Body parameters, comma-separated)</label>
                            <input type="text" name="variables" placeholder="e.g. Ahmad,porto,SAR" class="form-control form-control-sm">
                            <div class="form-text mt-1 text-muted" style="font-size:11px;">Enter variables in matching sequence. E.g. Ahmad, Porto.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send-check"></i> Dispatch Broadcast Campaign</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const selectedIds = new Set();
let selectAllFiltered = false;
const employeesMap = <?php echo json_encode($employeesMap); ?>;

document.addEventListener('DOMContentLoaded', () => {
    bindRowCheckboxes();
    bindPagination();
    applyCheckboxState();
});

function bindRowCheckboxes() {
    const checkAll = document.getElementById('check-all');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');

    if (checkAll) {
        checkAll.addEventListener('change', (e) => {
            const checked = e.target.checked;
            rowCheckboxes.forEach(cb => {
                cb.checked = checked;
                const val = parseInt(cb.value);
                if (checked) {
                    selectedIds.add(val);
                } else {
                    selectedIds.delete(val);
                }
            });
            updateBulkBanner();
        });
    }

    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', (e) => {
            const val = parseInt(e.target.value);
            if (e.target.checked) {
                selectedIds.add(val);
            } else {
                selectedIds.delete(val);
            }
            if (checkAll) {
                checkAll.checked = Array.from(rowCheckboxes).every(el => el.checked);
            }
            updateBulkBanner();
        });
    });

    const selectAllBtn = document.getElementById('select-all-filtered-btn');
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', () => {
            selectAllFiltered = true;
            updateBulkBanner();
        });
    }
}

function bindPagination() {
    document.querySelectorAll('.pagination-wrapper .page-link').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const url = new URL(link.href);
            const page = url.searchParams.get('page') || 1;
            goToPage(page);
        });
    });
}

function applyCheckboxState() {
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');
    rowCheckboxes.forEach(cb => {
        const val = parseInt(cb.value);
        cb.checked = selectedIds.has(val) || selectAllFiltered;
    });

    const checkAll = document.getElementById('check-all');
    if (checkAll && rowCheckboxes.length > 0) {
        checkAll.checked = Array.from(rowCheckboxes).every(el => el.checked);
    }
}

function goToPage(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);

    // Show loading overlay
    document.getElementById('table-loading-overlay').classList.remove('d-none');

    fetch(url.toString())
    .then(r => r.text())
    .then(html => {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        // Update Table contents
        document.querySelector('#crm-table tbody').innerHTML = doc.querySelector('#crm-table tbody').innerHTML;
        
        // Update pagination wrapper
        const oldPag = document.querySelector('.pagination-wrapper');
        const newPag = doc.querySelector('.pagination-wrapper');
        if (oldPag && newPag) {
            oldPag.innerHTML = newPag.innerHTML;
        }

        // Re-bind actions
        bindRowCheckboxes();
        bindPagination();
        applyCheckboxState();

        // Push state in history URL
        window.history.pushState(null, '', url.toString());

        // Hide loading overlay
        document.getElementById('table-loading-overlay').classList.add('d-none');
    }).catch(() => {
        document.getElementById('table-loading-overlay').classList.add('d-none');
        alert("Failed to load page parameters.");
    });
}

function updateBulkBanner() {
    const banner = document.getElementById('bulk-actions-banner');
    const countDisplay = document.getElementById('selected-count');
    const selectAllBtn = document.getElementById('select-all-filtered-btn');
    const allSelectedIndicator = document.getElementById('all-selected-indicator');

    if (selectedIds.size > 0 || selectAllFiltered) {
        banner.classList.remove('d-none');
        banner.classList.add('d-flex');

        if (selectAllFiltered) {
            countDisplay.innerText = "<?php echo $totalCount; ?>";
            if (selectAllBtn) selectAllBtn.classList.add('d-none');
            if (allSelectedIndicator) allSelectedIndicator.classList.remove('d-none');
        } else {
            countDisplay.innerText = selectedIds.size;
            if (allSelectedIndicator) allSelectedIndicator.classList.add('d-none');
            if (selectedIds.size < <?php echo $totalCount; ?>) {
                if (selectAllBtn) selectAllBtn.classList.remove('d-none');
            } else {
                if (selectAllBtn) selectAllBtn.classList.add('d-none');
            }
        }
    } else {
        banner.classList.add('d-none');
        banner.classList.remove('d-flex');
        selectAllFiltered = false;
    }
}

function clearSelection() {
    selectedIds.clear();
    selectAllFiltered = false;
    applyCheckboxState();
    updateBulkBanner();
}

// ── FETCH CAMPAIGN VALIDATION & STATS SUMMARY ──
function openBulkWhatsAppModal() {
    // Generate pre-validation payload
    const formParams = new URLSearchParams();
    formParams.append('action', 'validate_campaign');
    formParams.append('csrf_token', '<?php echo CsrfHelper::generateToken("customers_crm"); ?>');
    formParams.append('target_type', selectAllFiltered ? 'filtered' : 'selected');

    if (!selectAllFiltered) {
        selectedIds.forEach(id => formParams.append('selected_ids[]', id));
    }
    
    // Add current URL filters to payload in case of filtered target
    const currentUrlParams = new URLSearchParams(window.location.search);
    currentUrlParams.forEach((val, key) => formParams.append(key, val));

    // Show loading statistics placeholders
    document.getElementById('wa-total-selected').innerText = "...";
    document.getElementById('wa-estimated-send').innerText = "...";
    document.getElementById('wa-invalid-count').innerText = "...";
    document.getElementById('wa-duplicate-count').innerText = "...";
    document.getElementById('wa-blacklist-count').innerText = "...";

    fetch('../api/crm_actions_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formParams.toString()
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const s = data.stats;
            document.getElementById('wa-total-selected').innerText = s.total;
            document.getElementById('wa-estimated-send').innerText = s.estimated;
            document.getElementById('wa-invalid-count').innerText = s.invalid;
            document.getElementById('wa-duplicate-count').innerText = s.duplicates;
            document.getElementById('wa-blacklist-count').innerText = s.blacklist;
        } else {
            alert("Pre-validation failed: " + data.error);
        }
    });

    const container = document.getElementById('selected-ids-container');
    container.innerHTML = '';
    document.getElementById('whatsapp-target-type').value = selectAllFiltered ? 'filtered' : 'selected';
    if (!selectAllFiltered) {
        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = id;
            container.appendChild(input);
        });
    }

    const modal = new bootstrap.Modal(document.getElementById('modal-bulk-whatsapp'));
    modal.show();
}

function submitBulkWhatsApp(ev) {
    ev.preventDefault();
    if (!confirm("Are you sure you want to schedule and dispatch this Campaign?")) return;

    const form = document.getElementById('form-bulk-whatsapp');
    const data = new FormData(form);
    
    // Append URL filters if target type is filtered
    if (document.getElementById('whatsapp-target-type').value === 'filtered') {
        const currentUrlParams = new URLSearchParams(window.location.search);
        currentUrlParams.forEach((val, key) => data.append(key, val));
    }

    fetch('../api/crm_actions_handler.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modal-bulk-whatsapp')).hide();
            clearSelection();
            
            // Trigger dynamic toast
            const container = document.getElementById('globalToastContainer');
            if (container) {
                const toastId = 'toast-' + Date.now();
                container.insertAdjacentHTML('beforeend', `
                    <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                        <div class="toast-header bg-success text-white">
                            <i class="bi bi-check-circle me-2"></i>
                            <strong class="me-auto">Campaign Queued</strong>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Close"></button>
                        </div>
                        <div class="toast-body">✅ Campaign successfully queued! Broadcast worker has been launched.</div>
                    </div>
                `);
                new bootstrap.Toast(document.getElementById(toastId)).show();
            }
        } else {
            alert("Dispatch failed: " + res.error);
        }
    });
}

function toggleWhatsAppSendType(type) {
    const sectionText = document.getElementById('section-free-text');
    const sectionTemplate = document.getElementById('section-template');

    if (type === 'text') {
        sectionText.classList.remove('d-none');
        sectionTemplate.classList.add('d-none');
        document.getElementById('wa-message-text').setAttribute('required', 'required');
        document.getElementById('wa-template-name').removeAttribute('required');
    } else {
        sectionText.classList.add('d-none');
        sectionTemplate.classList.remove('d-none');
        document.getElementById('wa-message-text').removeAttribute('required');
        document.getElementById('wa-template-name').setAttribute('required', 'required');
    }
}

function insertQuickReplyText(text) {
    document.getElementById('wa-message-text').value = text;
}

// ── BULK ROUTING & OWNER ASSIGNMENT ──
function openBulkAssignModal() {
    const container = document.getElementById('assign-ids-container');
    container.innerHTML = '';
    document.getElementById('assign-target-type').value = selectAllFiltered ? 'filtered' : 'selected';
    if (!selectAllFiltered) {
        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = id;
            container.appendChild(input);
        });
    }

    const modal = new bootstrap.Modal(document.getElementById('modal-bulk-assign'));
    modal.show();
}

function toggleAssignStrategy(val) {
    const wrapper = document.getElementById('wrapper-manual-assignee');
    if (val === 'manual') {
        wrapper.classList.remove('d-none');
    } else {
        wrapper.classList.add('d-none');
    }
}

function submitBulkAssign(ev) {
    ev.preventDefault();
    const form = document.getElementById('form-bulk-assign');
    const data = new FormData(form);

    if (document.getElementById('assign-target-type').value === 'filtered') {
        const currentUrlParams = new URLSearchParams(window.location.search);
        currentUrlParams.forEach((val, key) => data.append(key, val));
    }

    fetch('../api/crm_actions_handler.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modal-bulk-assign')).hide();
            clearSelection();
            goToPage(1);
            alert("Customer routing assignments updated.");
        } else {
            alert(res.error);
        }
    });
}

// ── BULK TAGS ──
function openBulkTagsModal(tagAction) {
    document.getElementById('tags-action-type').value = tagAction;
    document.getElementById('tags-modal-title').innerText = tagAction === 'add' ? 'Bulk Add Tag' : 'Bulk Remove Tag';
    
    const container = document.getElementById('tags-ids-container');
    container.innerHTML = '';
    document.getElementById('tags-target-type').value = selectAllFiltered ? 'filtered' : 'selected';
    if (!selectAllFiltered) {
        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = id;
            container.appendChild(input);
        });
    }

    const modal = new bootstrap.Modal(document.getElementById('modal-bulk-tags'));
    modal.show();
}

function submitBulkTags(ev) {
    ev.preventDefault();
    const form = document.getElementById('form-bulk-tags');
    const data = new FormData(form);

    if (document.getElementById('tags-target-type').value === 'filtered') {
        const currentUrlParams = new URLSearchParams(window.location.search);
        currentUrlParams.forEach((val, key) => data.append(key, val));
    }

    fetch('../api/crm_actions_handler.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modal-bulk-tags')).hide();
            clearSelection();
            goToPage(1);
            alert(res.message);
        } else {
            alert(res.error);
        }
    });
}

// ── BULK INTERNAL NOTE ──
function openBulkNoteModal() {
    const container = document.getElementById('note-ids-container');
    container.innerHTML = '';
    document.getElementById('note-target-type').value = selectAllFiltered ? 'filtered' : 'selected';
    if (!selectAllFiltered) {
        selectedIds.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_ids[]';
            input.value = id;
            container.appendChild(input);
        });
    }

    const modal = new bootstrap.Modal(document.getElementById('modal-bulk-note'));
    modal.show();
}

function submitBulkNote(ev) {
    ev.preventDefault();
    const form = document.getElementById('form-bulk-note');
    const data = new FormData(form);

    if (document.getElementById('note-target-type').value === 'filtered') {
        const currentUrlParams = new URLSearchParams(window.location.search);
        currentUrlParams.forEach((val, key) => data.append(key, val));
    }

    fetch('../api/crm_actions_handler.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modal-bulk-note')).hide();
            clearSelection();
            alert("Internal note saved to selected customer profiles.");
        } else {
            alert(res.error);
        }
    });
}

// ── BULK DELETE ──
function triggerBulkDelete() {
    if (!confirm("Are you sure you want to permanently delete these customer profiles? This action is irreversible.")) return;

    const data = new FormData();
    data.append('action', 'delete_selected');
    data.append('csrf_token', '<?php echo CsrfHelper::generateToken("customers_crm"); ?>');
    data.append('target_type', selectAllFiltered ? 'filtered' : 'selected');

    if (!selectAllFiltered) {
        selectedIds.forEach(id => data.append('selected_ids[]', id));
    } else {
        const currentUrlParams = new URLSearchParams(window.location.search);
        currentUrlParams.forEach((val, key) => data.append(key, val));
    }

    fetch('../api/crm_actions_handler.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            clearSelection();
            goToPage(1);
            alert("Customer profiles deleted successfully.");
        } else {
            alert("Deletion failed: " + res.error);
        }
    });
}

function triggerExcelExport() {
    const params = new URLSearchParams();
    params.append('action', 'export');
    params.append('target_type', selectAllFiltered ? 'filtered' : 'selected');

    if (!selectAllFiltered) {
        params.append('selected_ids', Array.from(selectedIds).join(','));
    } else {
        const currentUrlParams = new URLSearchParams(window.location.search);
        currentUrlParams.forEach((val, key) => params.append(key, val));
    }

    window.location.href = '../api/crm_actions_handler.php?' + params.toString();
}

function exportFullFiltered() {
    const params = new URLSearchParams(window.location.search);
    params.append('action', 'export');
    params.append('target_type', 'filtered');
    window.location.href = '../api/crm_actions_handler.php?' + params.toString();
}

function viewCustomerDetails(cust) {
    const body = document.getElementById('details-modal-body');
    const ownerName = employeesMap[cust.assigned_to] || 'Unassigned';
    
    body.innerHTML = `
        <table class="table table-bordered table-sm m-0" style="font-size:13px;">
            <tr><th width="150" class="bg-light">Mobile Number</th><td><code>${cust.mobile}</code></td></tr>
            <tr><th class="bg-light">Arabic Name</th><td>${cust.full_name_ar || '-'}</td></tr>
            <tr><th class="bg-light">English Name</th><td>${cust.full_name_en || '-'}</td></tr>
            <tr><th class="bg-light">National ID</th><td>${cust.national_id || '-'}</td></tr>
            <tr><th class="bg-light">Nationality</th><td>${cust.nationality || '-'}</td></tr>
            <tr><th class="bg-light">Gender</th><td>${cust.gender || '-'}</td></tr>
            <tr><th class="bg-light">Employer</th><td>${cust.employer || '-'}</td></tr>
            <tr><th class="bg-light">Project Name</th><td>${cust.project_name || '-'}</td></tr>
            <tr><th class="bg-light">Program Name</th><td>${cust.program_name || '-'}</td></tr>
            <tr><th class="bg-light">Owner / Assignee</th><td><span class="badge bg-dark">${ownerName}</span></td></tr>
            <tr><th class="bg-light">Assignment Status</th><td><span class="badge bg-secondary">${cust.assignment_status.toUpperCase()}</span></td></tr>
            <tr><th class="bg-light">Source File</th><td>${cust.source_file || '-'}</td></tr>
            <tr><th class="bg-light">Created Date</th><td>${cust.created_at || '-'}</td></tr>
            <tr><th class="bg-light">Last Updated</th><td>${cust.updated_at || '-'}</td></tr>
        </table>

        <div id="customer-deal-details-wrapper" class="mt-3 border p-3 rounded bg-white">
            <h6 class="fw-bold mb-2 text-primary"><i class="bi bi-piggy-bank me-1"></i> CRM Sales Deal</h6>
            <div class="text-center py-2 text-muted">Loading CRM Sales Deal parameters...</div>
        </div>
    `;
    const modal = new bootstrap.Modal(document.getElementById('modal-view-customer'));
    modal.show();

    // Fetch deal info
    fetch('../api/deals_handler.php?action=get_customer_deal&customer_id=' + cust.id)
    .then(r => r.json())
    .then(data => {
        const wrapper = document.getElementById('customer-deal-details-wrapper');
        if (data.success && data.deal) {
            const d = data.deal;
            wrapper.innerHTML = `
                <h6 class="fw-bold mb-2 text-primary"><i class="bi bi-piggy-bank me-1"></i> CRM Sales Deal</h6>
                <table class="table table-sm table-bordered m-0" style="font-size:12px;">
                    <tr><th class="bg-light" width="150">Pipeline Stage</th><td><span class="badge bg-primary">${d.stage_name}</span></td></tr>
                    <tr><th class="bg-light">Deal Value</th><td class="text-success fw-bold">${parseFloat(d.estimated_value).toFixed(2)} ${d.currency}</td></tr>
                    <tr><th class="bg-light">Expected Close Date</th><td>${d.expected_close_date || '-'}</td></tr>
                    <tr><th class="bg-light">Last Activity</th><td>${d.last_activity || '-'}</td></tr>
                    <tr><th class="bg-light">Next Follow-up</th><td><span class="text-warning fw-bold">${d.next_followup || '-'}</span></td></tr>
                </table>
            `;
        } else {
            wrapper.innerHTML = `
                <h6 class="fw-bold mb-2 text-primary"><i class="bi bi-piggy-bank me-1"></i> CRM Sales Deal</h6>
                <div class="text-muted small"><i class="bi bi-info-circle me-1"></i> No active CRM sales pipeline deal exists for this customer.</div>
            `;
        }
    }).catch(() => {
        const wrapper = document.getElementById('customer-deal-details-wrapper');
        if (wrapper) wrapper.innerHTML = '<div class="text-danger small">Failed to load CRM deal info.</div>';
    });
}

function openEditCustomerModal(cust) {
    document.getElementById('customer-modal-title').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i>Edit Customer Record';
    document.getElementById('customer-id').value = cust.id;
    document.getElementById('customer-mobile').value = cust.mobile;
    document.getElementById('customer-national-id').value = cust.national_id || '';
    document.getElementById('customer-name-ar').value = cust.full_name_ar || '';
    document.getElementById('customer-name-en').value = cust.full_name_en || '';
    document.getElementById('customer-gender').value = cust.gender || '';
    document.getElementById('customer-nationality').value = cust.nationality || '';
    document.getElementById('customer-employer').value = cust.employer || '';
    document.getElementById('customer-project').value = cust.project_name || '';
    document.getElementById('customer-program').value = cust.program_name || '';

    const modal = new bootstrap.Modal(document.getElementById('modal-add-customer'));
    modal.show();
}

document.getElementById('modal-add-customer').addEventListener('hidden.bs.modal', function () {
    document.getElementById('customer-modal-title').innerHTML = '<i class="bi bi-person-plus text-primary me-2"></i>Add Manual Customer';
    document.getElementById('customer-id').value = '';
    document.getElementById('customer-form').reset();
});
</script>

<?php include("../layouts/footer.php"); ?>
