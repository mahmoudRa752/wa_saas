<?php
/**
 * WA Manager — Deals CRM AJAX API Handler
 * File: api/deals_handler.php
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

use Core\TenantContext;
use Core\Customer\CustomerRepository;
use Core\Deal\DealRepository;
use Core\Deal\DealService;
use Core\Auth\CsrfHelper;

header("Content-Type: application/json");

if (!isset($_SESSION['company_id'])) {
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

$ctx = TenantContext::fromSession();
$companyId = $ctx->companyId;
$role = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];

$custRepo = new CustomerRepository($conn);
$dealRepo = new DealRepository($conn);
$dealService = new DealService($dealRepo, $custRepo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'deals_crm')) {
        echo json_encode(["success" => false, "error" => "Invalid security token."]);
        exit;
    }

    if ($action === 'save_deal') {
        $id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
        $customerId = (int)$_POST['customer_id'];
        $assignedTo = !empty($_POST['assigned_to']) ? (int)$_POST['assigned_to'] : null;
        $stageId = (int)$_POST['stage_id'];
        $estimatedValue = (float)($_POST['estimated_value'] ?? 0.0);
        $currency = trim($_POST['currency'] ?? 'SAR');
        $probability = (int)($_POST['probability'] ?? 0);
        $expectedCloseDate = !empty($_POST['expected_close_date']) ? $_POST['expected_close_date'] : null;
        $source = trim($_POST['source'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Employee assignment security checks
        if ($role === 'employee') {
            if ($id !== null) {
                // Verify ownership of the deal
                $oldDeal = $dealRepo->findById($id, $ctx, $role, $userId);
                if (!$oldDeal) {
                    echo json_encode(["success" => false, "error" => "Access Denied: You do not own this deal."]);
                    exit;
                }
            }
            // Enforce employee assignment to themselves
            $assignedTo = $userId;
        }

        $deal = new \Core\Deal\Deal(
            id: $id,
            companyId: $companyId,
            customerId: $customerId,
            assignedTo: $assignedTo,
            stageId: $stageId,
            estimatedValue: $estimatedValue,
            currency: $currency,
            probability: $probability,
            expectedCloseDate: $expectedCloseDate,
            source: $source,
            notes: $notes,
            createdBy: $id ? null : $userId,
            createdAt: null,
            updatedAt: null
        );

        $res = $dealService->saveDeal($ctx, $deal, $userId);
        echo json_encode($res);
        exit;
    }

    elseif ($action === 'update_stage') {
        $dealId = (int)$_POST['deal_id'];
        $stageId = (int)$_POST['stage_id'];
        $res = $dealService->updateDealStage($ctx, $dealId, $stageId, $role, $userId);
        echo json_encode(["success" => $res]);
        exit;
    }

    elseif ($action === 'add_followup') {
        $dealId = (int)$_POST['deal_id'];
        
        // Security check
        $deal = $dealRepo->findById($dealId, $ctx, $role, $userId);
        if (!$deal) {
            echo json_encode(["success" => false, "error" => "Deal not found or access denied."]);
            exit;
        }

        $type = $_POST['type'] ?? 'task';
        $date = $_POST['followup_date'] ?? date('Y-m-d');
        $time = $_POST['followup_time'] ?? date('H:i');
        $reminder = !empty($_POST['reminder']);
        $notes = trim($_POST['notes'] ?? '');

        $fid = $dealRepo->createFollowup($companyId, $dealId, $type, $date, $time, $reminder, $notes);
        if ($fid > 0) {
            $dealRepo->logActivity($companyId, $dealId, 'followup', "Scheduled followup: " . ucfirst($type) . " for $date $time.");
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => "Failed to create follow-up reminder."]);
        }
        exit;
    }

    elseif ($action === 'complete_followup') {
        $fid = (int)$_POST['id'];
        $dealId = (int)$_POST['deal_id'];
        
        // Security check
        $deal = $dealRepo->findById($dealId, $ctx, $role, $userId);
        if (!$deal) {
            echo json_encode(["success" => false, "error" => "Access denied."]);
            exit;
        }

        $completed = !empty($_POST['completed']);
        $res = $dealRepo->updateFollowup($companyId, $fid, $completed);
        if ($res) {
            $statusLabel = $completed ? 'completed' : 'uncompleted';
            $dealRepo->logActivity($companyId, $dealId, 'followup', "Follow-up marked as $statusLabel.");
        }
        echo json_encode(["success" => $res]);
        exit;
    }

    elseif ($action === 'add_note') {
        $dealId = (int)$_POST['deal_id'];
        
        // Security check
        $deal = $dealRepo->findById($dealId, $ctx, $role, $userId);
        if (!$deal) {
            echo json_encode(["success" => false, "error" => "Access denied."]);
            exit;
        }

        $noteText = trim($_POST['note_text'] ?? '');
        if ($noteText !== '') {
            $dealService->addDealNote($ctx, $dealId, $noteText);
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "error" => "Empty note body."]);
        }
        exit;
    }

    elseif ($action === 'delete_deal') {
        if ($role !== 'admin') {
            echo json_encode(["success" => false, "error" => "Admin access only."]);
            exit;
        }
        $id = (int)$_POST['id'];
        $res = $dealRepo->delete($id, $ctx);
        echo json_encode(["success" => $res]);
        exit;
    }
}

// GET queries
elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'get_customer_deal') {
        $customerId = (int)$_GET['customer_id'];
        $customer = $custRepo->findById($customerId, $ctx, $role, $userId);
        if (!$customer) {
            echo json_encode(["success" => false, "error" => "Access denied."]);
            exit;
        }
        $summary = $dealRepo->getCustomerDealSummary($ctx, $customerId);
        if ($summary) {
            echo json_encode([
                "success" => true,
                "deal" => $summary
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "error" => "No active CRM deal for this customer."
            ]);
        }
        exit;
    }

    if ($action === 'get_timeline') {
        $dealId = (int)$_GET['deal_id'];
        $deal = $dealRepo->findById($dealId, $ctx, $role, $userId);
        if (!$deal) {
            echo json_encode(["success" => false, "error" => "Access denied."]);
            exit;
        }
        $activities = $dealRepo->getActivities($dealId);
        $followups = $dealRepo->getFollowups($dealId);
        
        echo json_encode([
            "success" => true,
            "activities" => $activities,
            "followups" => $followups
        ]);
        exit;
    }
}

echo json_encode(["success" => false, "error" => "Bad Request"]);
exit;
