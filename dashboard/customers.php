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

use Core\TenantContext;
use Core\Customer\Customer;
use Core\Customer\CustomerRepository;
use Core\Customer\CustomerService;
use Core\Auth\CsrfHelper;
use Core\SavedReply\SavedReplyRepository;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$ctx = TenantContext::fromSession();
$companyId = $ctx->companyId;

$customerRepo = new CustomerRepository($conn);
$customerService = new CustomerService($customerRepo);
$savedReplyRepo = new SavedReplyRepository($conn);

// Fetch Canned replies for templates selector
$cannedReplies = $savedReplyRepo->findAll($ctx);

// Gather request filters
$filters = [
    'gender' => $_GET['gender'] ?? '',
    'nationality' => $_GET['nationality'] ?? '',
    'employer' => $_GET['employer'] ?? '',
    'project_name' => $_GET['project_name'] ?? '',
    'program_name' => $_GET['program_name'] ?? '',
    'imported_date' => $_GET['imported_date'] ?? '',
    'national_id_status' => $_GET['national_id_status'] ?? '',
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
        $error = "✗ Invalid security token. Action cancelled.";
    } else {
        $action = $_POST['action'];

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
                // Fetch entire filtered list
                $ids = $customerService->getFilteredCustomerIds($ctx, $filters, $searchQuery);
                if (!empty($ids)) {
                    // Chunk query to avoid limit constraints on parameter bindings
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

// Fetch lists matching filters
$results = $customerService->getPaginatedCustomers($ctx, $filters, $searchQuery, $page, $limit, $sortBy, $sortOrder);
$customersList = $results['data'];
$totalCount = $results['total'];
$totalPages = $results['pages'];

// Fetch filter dropdown selectors dynamically from the repository
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
            <small class="text-secondary">Manage contact data sheet imports, run segment filters, and dispatch bulk WhatsApp messages.</small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal-add-customer"><i class="bi bi-plus-lg me-1"></i> Add Customer</button>
            <button class="btn btn-success btn-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal-import-customers"><i class="bi bi-file-earmark-excel me-1"></i> Import Sheet</button>
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
                <input type="text" name="q" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search Name, Mobile, Employer, ID, Project..." class="form-control form-control-sm border-start-0">
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
        <div class="col-md-3">
            <label class="form-label text-secondary fw-semibold small mb-1">Project</label>
            <select name="project_name" class="form-select form-select-sm">
                <option value="">All Projects</option>
                <?php foreach($dropdowns['projects'] as $val): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $filters['project_name'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label text-secondary fw-semibold small mb-1">Program</label>
            <select name="program_name" class="form-select form-select-sm">
                <option value="">All Programs</option>
                <?php foreach($dropdowns['programs'] as $val): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $filters['program_name'] === $val ? 'selected' : ''; ?>><?php echo htmlspecialchars($val); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label text-secondary fw-semibold small mb-1">National ID Status</label>
            <select name="national_id_status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="has" <?php echo $filters['national_id_status'] === 'has' ? 'selected' : ''; ?>>Has ID/Residence</option>
                <option value="missing" <?php echo $filters['national_id_status'] === 'missing' ? 'selected' : ''; ?>>Missing ID/Residence</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm flex-fill fw-semibold"><i class="bi bi-filter me-1"></i> Apply Filters</button>
            <a href="customers.php" class="btn btn-outline-secondary btn-sm px-3 fw-semibold"><i class="bi bi-arrow-counterclockwise"></i></a>
        </div>
    </div>
</form>

<!-- Bulk Actions Banner (Floating dynamic alert) -->
<div id="bulk-actions-banner" class="alert alert-dark p-3 shadow border-0 d-none justify-content-between align-items-center mb-3">
    <div class="d-flex align-items-center gap-2 text-white">
        <i class="bi bi-check2-square text-success fs-5"></i>
        <span>Selected: <strong id="selected-count">0</strong> customers.</span>
        <button type="button" id="select-all-filtered-btn" class="btn btn-outline-light btn-sm ms-3 d-none">Select all <?php echo $totalCount; ?> matches</button>
        <span id="all-selected-indicator" class="badge bg-success d-none">All matches selected</span>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary btn-sm fw-semibold" onclick="openBulkWhatsAppModal()"><i class="bi bi-whatsapp me-1"></i> Send WhatsApp</button>
        <button type="button" class="btn btn-outline-light btn-sm fw-semibold" onclick="triggerExcelExport()"><i class="bi bi-download me-1"></i> Export Excel</button>
    </div>
</div>

<!-- Customers Table -->
<div class="card p-3 shadow-sm border-0">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="small text-secondary">
            Showing <strong class="text-dark"><?php echo count($customersList); ?></strong> of <strong class="text-dark"><?php echo $totalCount; ?></strong> customers.
        </div>
        <div>
            <!-- Excel Export all/current filter button -->
            <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" onclick="exportFullFiltered()"><i class="bi bi-file-earmark-arrow-down me-1"></i> Export Filtered</button>
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
                    <th>Created At</th>
                    <th width="100" class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customersList)): ?>
                    <tr>
                        <td colspan="12" class="text-center text-muted py-4">No customers found. Import a spreadsheet or add manually.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customersList as $cust): ?>
                        <tr>
                            <td><input type="checkbox" name="customer_ids[]" value="<?php echo $cust->id; ?>" class="form-check-input row-checkbox" data-mobile="<?php echo htmlspecialchars($cust->mobile); ?>"></td>
                            <td class="fw-bold"><code><?php echo htmlspecialchars($cust->mobile); ?></code></td>
                            <td><?php echo htmlspecialchars($cust->fullNameAr ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->fullNameEn ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->nationalId ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->employer ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->projectName ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->programName ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->nationality ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($cust->gender ?? '-'); ?></td>
                            <td><small class="text-secondary"><?php echo date('Y-m-d', strtotime($cust->createdAt)); ?></small></td>
                            <td class="text-center">
                                <div class="d-flex justify-content-center gap-1">
                                    <button class="btn btn-outline-primary btn-xs py-0 px-2" style="font-size:11px;" onclick="viewCustomerDetails(<?php echo htmlspecialchars(json_encode($cust->toArray())); ?>)"><i class="bi bi-eye"></i></button>
                                    <button class="btn btn-outline-warning btn-xs py-0 px-2" style="font-size:11px;" onclick="openEditCustomerModal(<?php echo htmlspecialchars(json_encode($cust->toArray())); ?>)"><i class="bi bi-pencil"></i></button>
                                    <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this customer?');" style="margin:0;">
                                        <input type="hidden" name="action" value="delete_customer">
                                        <input type="hidden" name="id" value="<?php echo $cust->id; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customers_crm'); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-xs py-0 px-2" style="font-size:11px;"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination Controls -->
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

<!-- ==========================================
     MODALS
     ========================================== -->

<!-- Import Modal -->
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
                        <div class="form-text" style="font-size:11px;">Select a contact sheet. Mobile number columns (synonyms like جوال or mobile) are auto-mapped.</div>
                    </div>
                    
                    <div class="p-3 bg-light border rounded">
                        <h6 class="fw-bold mb-2" style="font-size:12.5px;">Auto-Mapping Syntax Rules:</h6>
                        <ul class="mb-0 text-secondary ps-3" style="font-size:11.5px;">
                            <li><strong>Mobile</strong>: جوال, هاتف, تليفون, mobile, phone</li>
                            <li><strong>Arabic Name</strong>: الاسم عربي, الاسم بالكامل عربي, name_ar</li>
                            <li><strong>English Name</strong>: الاسم انجليزي, الاسم بالكامل انجليزي, name_en</li>
                            <li><strong>National ID</strong>: الهوية الوطنية, رقم الهوية, national_id, iqama</li>
                            <li><strong>Nationality</strong>: الجنسية, nationality</li>
                            <li><strong>Gender</strong>: النوع, الجنس, gender, sex</li>
                            <li><strong>Employer</strong>: جهة العمل, employer, company</li>
                        </ul>
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

<!-- WhatsApp Broadcast Modal -->
<div class="modal fade" id="modal-bulk-whatsapp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="send_whatsapp">
                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('customers_crm'); ?>">
                <input type="hidden" name="target_type" id="whatsapp-target-type" value="selected">
                <!-- Keep inputs for bulk selections -->
                <div id="selected-ids-container"></div>
                
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark"><i class="bi bi-whatsapp text-success me-2"></i>Send WhatsApp Broadcast</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="font-size:13.5px;">
                    <div class="alert alert-light border p-3 mb-3 bg-light d-flex align-items-center gap-3">
                        <i class="bi bi-info-circle text-primary fs-4"></i>
                        <div>
                            <div class="fw-bold">Estimated Dispatch Statistics:</div>
                            <div class="text-secondary small">
                                Selected Contacts: <strong id="wa-selected-display" class="text-dark">0</strong> | 
                                Total Recipients: <strong id="wa-recipients-display" class="text-success">0</strong>
                            </div>
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

                    <!-- Free Text Form -->
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
                            <textarea name="message_text" id="wa-message-text" rows="5" class="form-control form-control-sm" placeholder="Write your broadcast message... Use variables like {name_ar} or {name_en} which are parsed client-side!"></textarea>
                        </div>
                    </div>

                    <!-- Template Form -->
                    <div id="section-template" class="d-none">
                        <div class="row g-2 mb-3">
                            <div class="col-md-8">
                                <label class="form-label fw-semibold text-secondary">Official Meta Template Name</label>
                                <input type="text" name="template_name" id="wa-template-name" placeholder="e.g. hello_world" class="form-control form-control-sm">
                                <small class="text-muted" style="font-size:11px;">Must exactly match the template name registered in Meta App dashboard.</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold text-secondary">Language Code</label>
                                <input type="text" name="template_language" id="wa-template-lang" value="en_US" placeholder="e.g. en_US" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send-check"></i> Dispatch Broadcast</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Keep track of check selections
const selectedIds = new Set();
let selectAllFiltered = false;

document.addEventListener('DOMContentLoaded', () => {
    const checkAll = document.getElementById('check-all');
    const rowCheckboxes = document.querySelectorAll('.row-checkbox');

    checkAll.addEventListener('change', (e) => {
        const checked = e.target.checked;
        rowCheckboxes.forEach(cb => {
            cb.checked = checked;
            if (checked) {
                selectedIds.add(parseInt(cb.value));
            } else {
                selectedIds.delete(parseInt(cb.value));
            }
        });
        updateBulkBanner();
    });

    rowCheckboxes.forEach(cb => {
        cb.addEventListener('change', (e) => {
            const val = parseInt(e.target.value);
            if (e.target.checked) {
                selectedIds.add(val);
            } else {
                selectedIds.delete(val);
            }
            // Check if check-all needs updating
            checkAll.checked = (selectedIds.size === rowCheckboxes.length);
            updateBulkBanner();
        });
    });

    const selectAllBtn = document.getElementById('select-all-filtered-btn');
    selectAllBtn.addEventListener('click', () => {
        selectAllFiltered = true;
        updateBulkBanner();
    });
});

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
            selectAllBtn.classList.add('d-none');
            allSelectedIndicator.classList.remove('d-none');
        } else {
            countDisplay.innerText = selectedIds.size;
            allSelectedIndicator.classList.add('d-none');
            // Show select all button if there are more filtered rows than selected on this page
            if (selectedIds.size < <?php echo $totalCount; ?>) {
                selectAllBtn.classList.remove('d-none');
            } else {
                selectAllBtn.classList.add('d-none');
            }
        }
    } else {
        banner.classList.add('d-none');
        banner.classList.remove('d-flex');
        selectAllFiltered = false;
    }
}

function openBulkWhatsAppModal() {
    // Populate selections inside hidden form fields
    const container = document.getElementById('selected-ids-container');
    container.innerHTML = '';

    const displayCount = selectAllFiltered ? <?php echo $totalCount; ?> : selectedIds.size;
    document.getElementById('wa-selected-display').innerText = displayCount;
    document.getElementById('wa-recipients-display').innerText = displayCount;

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
    const textInput = document.getElementById('wa-message-text');
    textInput.value = text;
}

function triggerExcelExport() {
    if (selectAllFiltered) {
        exportFullFiltered();
    } else {
        const ids = Array.from(selectedIds).join(',');
        window.location.href = '../api/export_customers.php?ids=' + ids;
    }
}

function exportFullFiltered() {
    // Collect all GET query params to export filtered state
    const params = new URLSearchParams(window.location.search);
    window.location.href = '../api/export_customers.php?' + params.toString();
}

function viewCustomerDetails(cust) {
    const body = document.getElementById('details-modal-body');
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
            <tr><th class="bg-light">Source File</th><td>${cust.source_file || '-'}</td></tr>
            <tr><th class="bg-light">Created Date</th><td>${cust.created_at || '-'}</td></tr>
            <tr><th class="bg-light">Last Updated</th><td>${cust.updated_at || '-'}</td></tr>
        </table>
    `;
    const modal = new bootstrap.Modal(document.getElementById('modal-view-customer'));
    modal.show();
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

// Reset form for manual additions
document.getElementById('modal-add-customer').addEventListener('hidden.bs.modal', function () {
    document.getElementById('customer-modal-title').innerHTML = '<i class="bi bi-person-plus text-primary me-2"></i>Add Manual Customer';
    document.getElementById('customer-id').value = '';
    document.getElementById('customer-form').reset();
});
</script>

<?php include("../layouts/footer.php"); ?>
