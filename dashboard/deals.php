<?php
/**
 * WA Manager — Sales Pipeline (Deals CRM Kanban)
 * File: dashboard/deals.php
 */
session_start();
require_once("../config/db.php");
require_once("../vendor/autoload.php");
require_once(__DIR__ . '/../core/TenantContext.php');
require_once(__DIR__ . '/../core/Customer/Customer.php');
require_once(__DIR__ . '/../core/Customer/CustomerRepository.php');
require_once(__DIR__ . '/../core/Deal/Deal.php');
require_once(__DIR__ . '/../core/Deal/DealRepository.php');
require_once(__DIR__ . '/../core/Deal/DealService.php');
require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');
require_once(__DIR__ . '/../core/Company/CompanyRepository.php');

use Core\TenantContext;
use Core\Customer\CustomerRepository;
use Core\Deal\DealRepository;
use Core\Deal\DealService;
use Core\Auth\CsrfHelper;
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

$custRepo = new CustomerRepository($conn);
$dealRepo = new DealRepository($conn);
$dealService = new DealService($dealRepo, $custRepo);
$companyRepo = new CompanyRepository($conn);

// Seeding default stages on load if none exist
$stages = $dealService->getStagesAndSeed($ctx);

// Handle pipeline settings updates (POST)
$success = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'deals_crm')) {
        $error = "✗ Invalid security token.";
    } else {
        $formAction = $_POST['form_action'];

        if ($formAction === 'create_stage') {
            if (!$isAdmin) {
                $error = "✗ Unauthorized.";
            } else {
                $name = trim($_POST['stage_name']);
                $order = (int)$_POST['sort_order'];
                if ($dealRepo->createStage($ctx, $name, $order)) {
                    $success = "✓ Stage '$name' created successfully.";
                    $stages = $dealRepo->getStages($ctx);
                }
            }
        }

        elseif ($formAction === 'update_stage_settings') {
            if (!$isAdmin) {
                $error = "✗ Unauthorized.";
            } else {
                $stageId = (int)$_POST['stage_id'];
                $name = trim($_POST['stage_name']);
                $order = (int)$_POST['sort_order'];
                if ($dealRepo->updateStage($ctx, $stageId, $name, $order)) {
                    $success = "✓ Stage settings updated.";
                    $stages = $dealRepo->getStages($ctx);
                }
            }
        }

        elseif ($formAction === 'delete_stage') {
            if (!$isAdmin) {
                $error = "✗ Unauthorized.";
            } else {
                $stageId = (int)$_POST['stage_id'];
                // Check if any deals are assigned to this stage
                $check = $conn->query("SELECT COUNT(*) FROM deals WHERE stage_id = $stageId");
                $dealCount = (int)($check->fetch_row()[0] ?? 0);
                if ($dealCount > 0) {
                    $error = "✗ Cannot delete stage containing active deals. Move deals to other stages first.";
                } else {
                    if ($dealRepo->deleteStage($ctx, $stageId)) {
                        $success = "✓ Stage deleted successfully.";
                        $stages = $dealRepo->getStages($ctx);
                    }
                }
            }
        }
    }
}

// Fetch employees list for deal selectors
$employeesList = $companyRepo->getEmployees($companyId);

// Fetch all customers for manual deal mapping
$customersList = $custRepo->getFilteredCustomers($ctx, [], '', $role, $userId);

include("../layouts/header.php");
?>

<style>
.kanban-board-container {
    display: flex;
    gap: 1rem;
    overflow-x: auto;
    padding-bottom: 1rem;
    align-items: flex-start;
}
.kanban-column {
    flex: 0 0 300px;
    width: 300px;
    background: #f8fafc;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    max-height: 75vh;
}
.kanban-column-header {
    padding: 12px 16px;
    font-weight: bold;
    border-bottom: 2px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fff;
    border-radius: 12px 12px 0 0;
}
.kanban-cards-list {
    padding: 10px;
    overflow-y: auto;
    flex-grow: 1;
    min-height: 150px;
}
.kanban-card {
    background: #fff;
    padding: 12px;
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
    margin-bottom: 8px;
    cursor: grab;
    transition: transform 0.2s, box-shadow 0.2s;
}
.kanban-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}
.kanban-card.dragging {
    opacity: 0.5;
}
</style>

<?php if ($success): ?>
    <div class="alert alert-success py-2 mb-3" style="font-size:13.5px;"><i class="bi bi-check-circle-fill me-1"></i><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger py-2 mb-3" style="font-size:13.5px;"><i class="bi bi-exclamation-triangle-fill me-1"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card p-3 shadow-sm border-0 mb-4 bg-light">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="fw-bold text-dark mb-0"><i class="bi bi-kanban text-primary me-2"></i>Sales Pipeline Board</h5>
            <small class="text-secondary">Track deals stages, values, probabilities, and close estimations.</small>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal-add-deal"><i class="bi bi-plus-lg me-1"></i> New Deal</button>
            <?php if ($isAdmin): ?>
                <button class="btn btn-outline-secondary btn-sm px-3 fw-semibold" data-bs-toggle="modal" data-bs-target="#modal-stage-settings"><i class="bi bi-gear me-1"></i> Stages Settings</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Kanban Grid -->
<div class="kanban-board-container mt-3">
    <?php foreach ($stages as $stage): 
        $dealsInStage = $dealRepo->getDealsByStage($ctx, $stage['id'], $role, $userId);
        $stageCount = count($dealsInStage);
        $stageValue = 0.0;
        foreach ($dealsInStage as $d) {
            $stageValue += (float)$d['estimated_value'];
        }
    ?>
        <div class="kanban-column" data-stage-id="<?php echo $stage['id']; ?>">
            <div class="kanban-column-header">
                <div>
                    <span class="text-dark fw-bold"><?php echo htmlspecialchars($stage['name']); ?></span>
                    <span class="badge bg-secondary ms-1 rounded-pill" style="font-size:10px;"><?php echo $stageCount; ?></span>
                </div>
                <small class="text-muted fw-semibold" style="font-size:11px;"><?php echo number_format($stageValue, 2); ?> SAR</small>
            </div>
            
            <div class="kanban-cards-list" ondragover="allowDrop(event)" ondrop="dropCard(event, <?php echo $stage['id']; ?>)">
                <?php foreach ($dealsInStage as $deal): ?>
                    <div class="kanban-card" 
                         draggable="true" 
                         id="deal-card-<?php echo $deal['id']; ?>" 
                         ondragstart="dragCard(event, <?php echo $deal['id']; ?>)"
                         onclick="openDealTimelineModal(<?php echo htmlspecialchars(json_encode($deal)); ?>)">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="badge bg-light text-dark border fw-bold" style="font-size:10px;"><?php echo htmlspecialchars($deal['source'] ?: 'No Source'); ?></span>
                            <span class="text-primary fw-bold" style="font-size:12.5px;"><?php echo number_format($deal['estimated_value'], 2); ?> <span style="font-size:9.5px;"><?php echo htmlspecialchars($deal['currency']); ?></span></span>
                        </div>
                        <h6 class="fw-bold text-dark mb-1" style="font-size:13.5px;"><?php echo htmlspecialchars($deal['full_name_ar'] ?: $deal['full_name_en'] ?: 'Unknown Customer'); ?></h6>
                        <div class="text-secondary mb-2" style="font-size:11.5px;"><i class="bi bi-phone me-1"></i><?php echo htmlspecialchars($deal['mobile']); ?></div>
                        
                        <div class="d-flex justify-content-between align-items-center" style="font-size:11px;">
                            <span class="text-muted"><i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($deal['employee_name'] ?: 'Unassigned'); ?></span>
                            <span class="fw-bold <?php echo $deal['probability'] >= 70 ? 'text-success' : ($deal['probability'] >= 40 ? 'text-warning' : 'text-danger'); ?>"><?php echo $deal['probability']; ?>%</span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- ==========================================
     MODALS
     ========================================== -->

<!-- Create / Edit Deal Modal -->
<div class="modal fade" id="modal-add-deal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="deal-crm-form" onsubmit="submitDealForm(event)">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-dark" id="deal-modal-title"><i class="bi bi-plus-lg text-primary me-2"></i>New Pipeline Deal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="font-size:13px;">
                    <input type="hidden" name="action" value="save_deal">
                    <input type="hidden" name="id" id="deal-id" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('deals_crm'); ?>">

                    <div class="row g-3">
                        <div class="col-md-6" id="customer-select-wrapper">
                            <label class="form-label text-secondary fw-semibold mb-1">Customer Profile (Required)</label>
                            <select name="customer_id" id="deal-customer-id" class="form-select form-select-sm" required>
                                <option value="">-- Choose Customer --</option>
                                <?php foreach ($customersList as $cust): ?>
                                    <option value="<?php echo $cust->id; ?>"><?php echo htmlspecialchars($cust->fullNameAr ?: $cust->fullNameEn ?: $cust->mobile); ?> (<?php echo htmlspecialchars($cust->mobile); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isAdmin): ?>
                            <div class="col-md-6">
                                <label class="form-label text-secondary fw-semibold mb-1">Assigned Agent</label>
                                <select name="assigned_to" id="deal-assigned-to" class="form-select form-select-sm">
                                    <option value="">-- Unassigned --</option>
                                    <?php foreach ($employeesList as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>"><?php echo htmlspecialchars($emp['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label text-secondary fw-semibold mb-1">Pipeline Stage</label>
                            <select name="stage_id" id="deal-stage-id" class="form-select form-select-sm" required>
                                <?php foreach ($stages as $stg): ?>
                                    <option value="<?php echo $stg['id']; ?>"><?php echo htmlspecialchars($stg['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-secondary fw-semibold mb-1">Estimated Value</label>
                            <input type="number" step="0.01" name="estimated_value" id="deal-estimated-value" placeholder="e.g. 5000" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label text-secondary fw-semibold mb-1">Currency</label>
                            <input type="text" name="currency" id="deal-currency" value="SAR" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary fw-semibold mb-1">Win Probability (%)</label>
                            <input type="number" name="probability" id="deal-probability" min="0" max="100" placeholder="e.g. 50" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary fw-semibold mb-1">Expected Close Date</label>
                            <input type="date" name="expected_close_date" id="deal-expected-close-date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label text-secondary fw-semibold mb-1">Source channel</label>
                            <input type="text" name="source" id="deal-source" placeholder="e.g. WhatsApp Inbound, Website" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label text-secondary fw-semibold mb-1">Notes</label>
                            <textarea name="notes" id="deal-notes" rows="3" placeholder="Brief explanation of the deal parameters..." class="form-control form-control-sm"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i> Save Deal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stages Settings Modal (Admin) -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="modal-stage-settings" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-gear text-secondary me-2"></i>CRM Pipeline Stages Configuration</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="font-size:13px;">
                <table class="table table-bordered table-sm">
                    <thead>
                        <tr>
                            <th>Stage Name</th>
                            <th>Sort Order</th>
                            <th width="120" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stages as $stg): ?>
                            <tr>
                                <form method="POST">
                                    <input type="hidden" name="form_action" value="update_stage_settings">
                                    <input type="hidden" name="stage_id" value="<?php echo $stg['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('deals_crm'); ?>">
                                    <td><input type="text" name="stage_name" value="<?php echo htmlspecialchars($stg['name']); ?>" class="form-control form-control-xs py-0" required></td>
                                    <td><input type="number" name="sort_order" value="<?php echo $stg['sort_order']; ?>" class="form-control form-control-xs py-0" style="width:70px;" required></td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            <button type="submit" class="btn btn-xs btn-outline-success py-0 px-2" style="font-size:11px;"><i class="bi bi-save"></i></button>
                                </form>
                                            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this pipeline stage?');">
                                                <input type="hidden" name="form_action" value="delete_stage">
                                                <input type="hidden" name="stage_id" value="<?php echo $stg['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('deals_crm'); ?>">
                                                <button type="submit" class="btn btn-xs btn-outline-danger py-0 px-2" style="font-size:11px;"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Add Stage row -->
                        <tr>
                            <form method="POST">
                                <input type="hidden" name="form_action" value="create_stage">
                                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('deals_crm'); ?>">
                                <td><input type="text" name="stage_name" placeholder="New Stage Name" class="form-control form-control-xs py-0" required></td>
                                <td><input type="number" name="sort_order" value="<?php echo count($stages); ?>" class="form-control form-control-xs py-0" style="width:70px;" required></td>
                                <td class="text-center">
                                    <button type="submit" class="btn btn-primary btn-xs px-3 py-0" style="font-size:11px;"><i class="bi bi-plus-lg"></i> Add</button>
                                </td>
                            </form>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Deal Details & Timeline Modal -->
<div class="modal fade" id="modal-deal-details" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold text-dark"><i class="bi bi-journal-check text-primary me-2"></i>Deal Workspace & Action Centre</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="font-size:13px;">
                <div class="row g-3">
                    <!-- Left Column: Deal Metadata & Update controls -->
                    <div class="col-md-5">
                        <div class="card p-3 mb-3 border bg-light">
                            <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-1 text-primary"></i> Deal Information</h6>
                            <div id="deal-info-attributes"></div>
                            
                            <hr>
                            <button type="button" class="btn btn-outline-warning btn-sm w-100 fw-semibold" onclick="triggerEditFromDetails()"><i class="bi bi-pencil-square me-1"></i> Edit Deal Parameters</button>
                            <?php if ($isAdmin): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 fw-semibold mt-2" onclick="triggerDeleteFromDetails()"><i class="bi bi-trash me-1"></i> Delete Deal Record</button>
                            <?php endif; ?>
                        </div>

                        <!-- Schedule Followup module -->
                        <div class="card p-3 border">
                            <h6 class="fw-bold mb-2"><i class="bi bi-alarm-fill text-warning me-1"></i> Schedule Follow-up Reminder</h6>
                            <form id="followup-form" onsubmit="submitFollowupForm(event)">
                                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('deals_crm'); ?>">
                                <input type="hidden" name="action" value="add_followup">
                                <input type="hidden" name="deal_id" id="followup-deal-id" value="">
                                
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label text-secondary small mb-1">Followup Channel</label>
                                        <select name="type" class="form-select form-select-sm" required>
                                            <option value="whatsapp">WhatsApp</option>
                                            <option value="call">Phone Call</option>
                                            <option value="meeting">Meeting</option>
                                            <option value="email">Email</option>
                                            <option value="task">General Task</option>
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-secondary small mb-1">Date</label>
                                        <input type="date" name="followup_date" class="form-control form-control-sm" required value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label text-secondary small mb-1">Time</label>
                                        <input type="time" name="followup_time" class="form-control form-control-sm" required value="<?php echo date('H:i'); ?>">
                                    </div>
                                    <div class="col-6 d-flex align-items-end pb-1">
                                        <div class="form-check form-switch mb-1">
                                            <input class="form-check-input" type="checkbox" name="reminder" id="rem-check" checked>
                                            <label class="form-check-label small" for="rem-check">Reminder</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <input type="text" name="notes" placeholder="e.g. Discuss qualified quote review proposal" class="form-control form-control-sm" required>
                                </div>
                                <button type="submit" class="btn btn-warning btn-sm w-100 fw-semibold text-dark"><i class="bi bi-clock-history me-1"></i> Save Followup</button>
                            </form>
                        </div>
                    </div>

                    <!-- Right Column: Timeline activities & Followups checklist -->
                    <div class="col-md-7">
                        <div class="card p-3 mb-3 border">
                            <h6 class="fw-bold mb-3"><i class="bi bi-clock-history text-secondary me-1"></i> Deal Timeline Activities</h6>
                            <div class="timeline-list" id="deal-activities-timeline" style="max-height: 250px; overflow-y: auto;">
                                <!-- Loaded dynamically -->
                            </div>
                            
                            <hr>
                            <!-- Note submission -->
                            <form id="note-form" onsubmit="submitNoteForm(event)">
                                <input type="hidden" name="csrf_token" value="<?php echo CsrfHelper::generateToken('deals_crm'); ?>">
                                <input type="hidden" name="action" value="add_note">
                                <input type="hidden" name="deal_id" id="note-deal-id" value="">
                                <div class="input-group input-group-sm">
                                    <input type="text" name="note_text" id="note-text-input" placeholder="Type a note to append inside the deal timeline..." class="form-control form-control-sm" required>
                                    <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg"></i> Add Note</button>
                                </div>
                            </form>
                        </div>

                        <!-- Followups Checklist -->
                        <div class="card p-3 border">
                            <h6 class="fw-bold mb-2"><i class="bi bi-list-check text-success me-1"></i> Scheduled Follow-ups Reminders</h6>
                            <div id="followups-checklist" style="max-height: 150px; overflow-y: auto;">
                                <!-- Loaded dynamically -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentDeal = null;

// Draggable board logic
let draggedDealId = null;

function dragCard(ev, dealId) {
    draggedDealId = dealId;
    ev.target.classList.add('dragging');
}

function allowDrop(ev) {
    ev.preventDefault();
}

function dropCard(ev, stageId) {
    ev.preventDefault();
    const card = document.getElementById('deal-card-' + draggedDealId);
    if (card) {
        card.classList.remove('dragging');
    }
    
    // AJAX update
    if (draggedDealId && stageId) {
        const formData = new FormData();
        formData.append('action', 'update_stage');
        formData.append('deal_id', draggedDealId);
        formData.append('stage_id', stageId);
        formData.append('csrf_token', '<?php echo CsrfHelper::generateToken("deals_crm"); ?>');

        fetch('../api/deals_handler.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Reload board to update sum headers
                window.location.reload();
            } else {
                alert("Failed to update stage: " + (data.error || 'Access denied.'));
            }
        }).catch(() => {
            alert("Network connection error.");
        });
    }
}

function openDealTimelineModal(deal) {
    currentDeal = deal;
    
    // Set IDs inside forms
    document.getElementById('followup-deal-id').value = deal.id;
    document.getElementById('note-deal-id').value = deal.id;

    // Fill left column metadata
    const info = document.getElementById('deal-info-attributes');
    info.innerHTML = `
        <table class="table table-bordered table-sm m-0" style="font-size:12px;">
            <tr><th class="bg-light">Customer</th><td><strong>${deal.full_name_ar || deal.full_name_en || 'Customer'}</strong></td></tr>
            <tr><th class="bg-light">Mobile</th><td><code>${deal.mobile}</code></td></tr>
            <tr><th class="bg-light">Value</th><td class="text-primary fw-bold">${parseFloat(deal.estimated_value).toFixed(2)} ${deal.currency}</td></tr>
            <tr><th class="bg-light">Win Prob %</th><td>${deal.probability}%</td></tr>
            <tr><th class="bg-light">Close date</th><td>${deal.expected_close_date || '-'}</td></tr>
            <tr><th class="bg-light">Source</th><td>${deal.source || '-'}</td></tr>
            <tr><th class="bg-light">Owner</th><td><span class="badge bg-dark">${deal.employee_name || 'Unassigned'}</span></td></tr>
            <tr><th class="bg-light">Notes</th><td>${deal.notes || '-'}</td></tr>
        </table>
    `;

    // Fetch Timeline activities feed
    fetchTimelineFeed(deal.id);

    const modal = new bootstrap.Modal(document.getElementById('modal-deal-details'));
    modal.show();
}

function fetchTimelineFeed(dealId) {
    fetch('../api/deals_handler.php?action=get_timeline&deal_id=' + dealId)
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Render Activities Timeline Feed
            const timeline = document.getElementById('deal-activities-timeline');
            timeline.innerHTML = '';
            if (data.activities.length === 0) {
                timeline.innerHTML = '<div class="text-muted text-center py-3">No activities registered.</div>';
            } else {
                data.activities.forEach(act => {
                    const icon = act.activity_type === 'stage_change' ? 'bi-shuffle text-warning' : (act.activity_type === 'message' ? 'bi-whatsapp text-success' : 'bi-journal-text text-primary');
                    timeline.innerHTML += `
                        <div class="d-flex gap-2 mb-2 p-2 border-bottom">
                            <i class="bi ${icon} fs-5"></i>
                            <div>
                                <div class="fw-bold" style="font-size:12px;">${act.description}</div>
                                <small class="text-muted" style="font-size:10px;">${act.created_at}</small>
                            </div>
                        </div>
                    `;
                });
            }

            // Render Follow-ups Checklist
            const followups = document.getElementById('followups-checklist');
            followups.innerHTML = '';
            if (data.followups.length === 0) {
                followups.innerHTML = '<div class="text-muted text-center py-2">No follow-ups scheduled.</div>';
            } else {
                data.followups.forEach(f => {
                    const checked = f.completed == 1 ? 'checked' : '';
                    const strike = f.completed == 1 ? 'text-decoration-line-through text-muted' : '';
                    followups.innerHTML += `
                        <div class="form-check d-flex align-items-center justify-content-between mb-1 py-1 border-bottom">
                            <div>
                                <input class="form-check-input" type="checkbox" value="${f.id}" ${checked} onchange="toggleFollowupCompleted(${f.id}, ${dealId}, this.checked)">
                                <label class="form-check-label small ${strike}">
                                    <strong>[${f.type.toUpperCase()}]</strong> ${f.notes} - <span class="text-secondary">${f.followup_date} ${f.followup_time}</span>
                                </label>
                            </div>
                        </div>
                    `;
                });
            }
        }
    });
}

function submitFollowupForm(ev) {
    ev.preventDefault();
    const form = document.getElementById('followup-form');
    const data = new FormData(form);

    fetch('../api/deals_handler.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            form.reset();
            fetchTimelineFeed(document.getElementById('followup-deal-id').value);
        } else {
            alert(res.error || 'Failed to save follow-up.');
        }
    });
}

function toggleFollowupCompleted(fid, dealId, checked) {
    const data = new FormData();
    data.append('action', 'complete_followup');
    data.append('id', fid);
    data.append('deal_id', dealId);
    data.append('completed', checked ? 1 : 0);
    data.append('csrf_token', '<?php echo CsrfHelper::generateToken("deals_crm"); ?>');

    fetch('../api/deals_handler.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            fetchTimelineFeed(dealId);
        } else {
            alert('Failed to update status.');
        }
    });
}

function submitNoteForm(ev) {
    ev.preventDefault();
    const input = document.getElementById('note-text-input');
    const dealId = document.getElementById('note-deal-id').value;

    const data = new FormData();
    data.append('action', 'add_note');
    data.append('deal_id', dealId);
    data.append('note_text', input.value);
    data.append('csrf_token', '<?php echo CsrfHelper::generateToken("deals_crm"); ?>');

    fetch('../api/deals_handler.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            input.value = '';
            fetchTimelineFeed(dealId);
        } else {
            alert(res.error || 'Failed to add note.');
        }
    });
}

function triggerEditFromDetails() {
    // Hide details modal
    bootstrap.Modal.getInstance(document.getElementById('modal-deal-details')).hide();

    // Populate inputs inside Create/Edit modal
    document.getElementById('deal-modal-title').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i>Edit Pipeline Deal';
    document.getElementById('deal-id').value = currentDeal.id;
    document.getElementById('deal-customer-id').value = currentDeal.customer_id;
    document.getElementById('deal-customer-id').setAttribute('disabled', 'disabled'); // lock customer select on edit
    if (document.getElementById('deal-assigned-to')) {
        document.getElementById('deal-assigned-to').value = currentDeal.assigned_to || '';
    }
    document.getElementById('deal-stage-id').value = currentDeal.stage_id;
    document.getElementById('deal-estimated-value').value = currentDeal.estimated_value;
    document.getElementById('deal-currency').value = currentDeal.currency;
    document.getElementById('deal-probability').value = currentDeal.probability;
    document.getElementById('deal-expected-close-date').value = currentDeal.expected_close_date || '';
    document.getElementById('deal-source').value = currentDeal.source || '';
    document.getElementById('deal-notes').value = currentDeal.notes || '';

    // Show Create/Edit modal
    const modal = new bootstrap.Modal(document.getElementById('modal-add-deal'));
    modal.show();
}

function triggerDeleteFromDetails() {
    if (confirm("Are you sure you want to delete this deal? This action is irreversible.")) {
        const data = new FormData();
        data.append('action', 'delete_deal');
        data.append('id', currentDeal.id);
        data.append('csrf_token', '<?php echo CsrfHelper::generateToken("deals_crm"); ?>');

        fetch('../api/deals_handler.php', {
            method: 'POST',
            body: data
        })
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                window.location.reload();
            } else {
                alert("Failed to delete deal: " + (res.error || 'Access denied.'));
            }
        });
    }
}

function submitDealForm(ev) {
    ev.preventDefault();
    const form = document.getElementById('deal-crm-form');
    // Enable disabled customer select before generating FormData so it gets submitted
    const custSelect = document.getElementById('deal-customer-id');
    custSelect.removeAttribute('disabled');
    
    const data = new FormData(form);

    fetch('../api/deals_handler.php', {
        method: 'POST',
        body: data
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            window.location.reload();
        } else {
            alert("Error: " + (res.error || 'Failed to save deal.'));
            // Re-disable if editing
            if (document.getElementById('deal-id').value !== '') {
                custSelect.setAttribute('disabled', 'disabled');
            }
        }
    }).catch(() => {
        alert("Server connection error.");
    });
}

// Reset form fields when closing add modal
document.getElementById('modal-add-deal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('deal-modal-title').innerHTML = '<i class="bi bi-plus-lg text-primary me-2"></i>New Pipeline Deal';
    document.getElementById('deal-id').value = '';
    document.getElementById('deal-customer-id').removeAttribute('disabled');
    document.getElementById('deal-crm-form').reset();
});
</script>

<?php include("../layouts/footer.php"); ?>
