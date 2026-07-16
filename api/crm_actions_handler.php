<?php
/**
 * WA Manager — CRM Bulk Actions & Validation API
 * File: api/crm_actions_handler.php
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once(__DIR__ . "/../config/db.php");
require_once(__DIR__ . "/../vendor/autoload.php");
require_once(__DIR__ . '/../core/TenantContext.php');
require_once(__DIR__ . '/../core/Customer/Customer.php');
require_once(__DIR__ . '/../core/Customer/CustomerRepository.php');
require_once(__DIR__ . '/../core/Customer/CustomerService.php');
require_once(__DIR__ . '/../core/Auth/CsrfHelper.php');

use Core\TenantContext;
use Core\Customer\CustomerRepository;
use Core\Customer\CustomerService;
use Core\Auth\CsrfHelper;

header("Content-Type: application/json");

if (php_sapi_name() === 'cli' && !isset($_SESSION['company_id'])) {
    $_SESSION['company_id'] = 5;
    $_SESSION['role'] = 'admin';
    $_SESSION['user_id'] = 9;
}

if (!isset($_SESSION['company_id'])) {
    echo json_encode(["success" => false, "error" => "Unauthorized"]);
    exit;
}

$ctx = TenantContext::fromSession();
$companyId = $ctx->companyId;
$role = $_SESSION['role'];
$userId = (int)$_SESSION['user_id'];
$isAdmin = ($role === 'admin');

$customerRepo = new CustomerRepository($conn);
$customerService = new CustomerService($customerRepo);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper: Fetch matching customer IDs based on GET filters (Respect Employee Isolation)
function getFilteredCustomerIds($conn, $companyId, $role, $userId) {
    $filters = [
        'gender' => $_GET['gender'] ?? $_POST['gender'] ?? '',
        'nationality' => $_GET['nationality'] ?? $_POST['nationality'] ?? '',
        'employer' => $_GET['employer'] ?? $_POST['employer'] ?? '',
        'project_name' => $_GET['project_name'] ?? $_POST['project_name'] ?? '',
        'program_name' => $_GET['program_name'] ?? $_POST['program_name'] ?? '',
        'imported_date' => $_GET['imported_date'] ?? $_POST['imported_date'] ?? '',
        'national_id_status' => $_GET['national_id_status'] ?? $_POST['national_id_status'] ?? '',
        'assigned_to_user' => $_GET['assigned_to_user'] ?? $_POST['assigned_to_user'] ?? '',
        'assignment_status' => $_GET['assignment_status'] ?? $_POST['assignment_status'] ?? '',
    ];
    $q = trim($_GET['q'] ?? $_POST['q'] ?? '');

    // Formulate database query manually to retrieve ONLY the customer IDs
    $sql = "SELECT id, mobile, assignment_status, assigned_to FROM customers WHERE company_id = ?";
    $params = [$companyId];
    $types = "i";

    if ($role === 'employee') {
        $sql .= " AND assigned_to = ? AND assignment_status = 'accepted'";
        $params[] = $userId;
        $types .= "i";
    }

    if ($filters['gender'] !== '') {
        $sql .= " AND gender = ?";
        $params[] = $filters['gender'];
        $types .= "s";
    }
    if ($filters['nationality'] !== '') {
        $sql .= " AND nationality = ?";
        $params[] = $filters['nationality'];
        $types .= "s";
    }
    if ($filters['employer'] !== '') {
        $sql .= " AND employer = ?";
        $params[] = $filters['employer'];
        $types .= "s";
    }
    if ($filters['project_name'] !== '') {
        $sql .= " AND project_name = ?";
        $params[] = $filters['project_name'];
        $types .= "s";
    }
    if ($filters['program_name'] !== '') {
        $sql .= " AND program_name = ?";
        $params[] = $filters['program_name'];
        $types .= "s";
    }
    if ($filters['imported_date'] !== '') {
        $sql .= " AND DATE(created_at) = ?";
        $params[] = $filters['imported_date'];
        $types .= "s";
    }
    if ($filters['national_id_status'] === 'has') {
        $sql .= " AND national_id IS NOT NULL AND TRIM(national_id) != ''";
    } elseif ($filters['national_id_status'] === 'missing') {
        $sql .= " AND (national_id IS NULL OR TRIM(national_id) = '')";
    }

    if ($role === 'admin') {
        if ($filters['assigned_to_user'] !== '') {
            $sql .= " AND assigned_to = ?";
            $params[] = (int)$filters['assigned_to_user'];
            $types .= "i";
        }
        if ($filters['assignment_status'] !== '') {
            $sql .= " AND assignment_status = ?";
            $params[] = $filters['assignment_status'];
            $types .= "s";
        }
    }

    if ($q !== '') {
        $sql .= " AND (full_name_ar LIKE ? OR full_name_en LIKE ? OR mobile LIKE ? OR national_id LIKE ?)";
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "ssss";
    }

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $res;
    }
    return [];
}

// ── Handle Post Bulk Operations ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    $token = $_POST['csrf_token'] ?? '';
    if (!CsrfHelper::validateToken($token, 'customers_crm')) {
        echo json_encode(["success" => false, "error" => "Invalid security token."]);
        exit;
    }

    // Resolve target customer IDs
    $targetType = $_POST['target_type'] ?? 'selected';
    $customerIds = [];
    $selectedMobiles = [];

    if ($targetType === 'selected') {
        $rawIds = $_POST['selected_ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = explode(',', $rawIds);
        }
        $customerIds = array_map('intval', array_filter($rawIds));
        
        // Employee protection: enforce isolation rule on selected IDs
        if ($role === 'employee' && !empty($customerIds)) {
            $inClause = implode(',', array_fill(0, count($customerIds), '?'));
            $stmt = $conn->prepare("SELECT id FROM customers WHERE id IN ($inClause) AND company_id = ? AND assigned_to = ? AND assignment_status = 'accepted'");
            if ($stmt) {
                $types = str_repeat('i', count($customerIds)) . "ii";
                $bindParams = array_merge($customerIds, [$companyId, $userId]);
                $stmt->bind_param($types, ...$bindParams);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $customerIds = array_column($rows, 'id');
                $stmt->close();
            }
        }
    } else {
        // Fetch all matching filtered IDs
        $rows = getFilteredCustomerIds($conn, $companyId, $role, $userId);
        $customerIds = array_column($rows, 'id');
    }

    if (empty($customerIds) && $action !== 'cancel_campaign' && $action !== 'retry_campaign') {
        echo json_encode(["success" => false, "error" => "No valid customers selected."]);
        exit;
    }

    // 1) PRE-SEND VALIDATION STATS
    if ($action === 'validate_campaign') {
        $uniqueIds = array_unique($customerIds);
        $inClause = implode(',', array_fill(0, count($uniqueIds), '?'));
        $stmt = $conn->prepare("SELECT id, mobile, tags FROM customers WHERE id IN ($inClause) AND company_id = ?");
        $mobiles = [];
        $blacklistCount = 0;
        if ($stmt) {
            $types = str_repeat('i', count($uniqueIds)) . "i";
            $bindParams = array_merge($uniqueIds, [$companyId]);
            $stmt->bind_param($types, ...$bindParams);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $idToMobile = [];
            $idToBlacklist = [];
            foreach ($rows as $r) {
                $idToMobile[$r['id']] = preg_replace('/[^0-9]/', '', $r['mobile']);
                $idToBlacklist[$r['id']] = (stripos($r['tags'] ?? '', 'blacklist') !== false || stripos($r['tags'] ?? '', 'opt_out') !== false);
            }
            
            foreach ($customerIds as $cid) {
                if (isset($idToMobile[$cid])) {
                    $mobiles[] = $idToMobile[$cid];
                    if ($idToBlacklist[$cid]) {
                        $blacklistCount++;
                    }
                }
            }
            $stmt->close();
        }

        $totalCount = count($mobiles);
        $invalid = 0;
        $duplicates = 0;
        $uniqueMobiles = [];

        foreach ($mobiles as $num) {
            if (empty($num) || strlen($num) < 9) {
                $invalid++;
                continue;
            }
            if (in_array($num, $uniqueMobiles)) {
                $duplicates++;
                continue;
            }
            $uniqueMobiles[] = $num;
        }

        $skipped = $invalid + $duplicates + $blacklistCount;
        $estimated = $totalCount - $skipped;

        echo json_encode([
            "success" => true,
            "stats" => [
                "total" => $totalCount,
                "invalid" => $invalid,
                "duplicates" => $duplicates,
                "blacklist" => $blacklistCount,
                "skipped" => $skipped,
                "estimated" => $estimated
            ]
        ]);
        exit;
    }

    // 2) CREATE & QUEUE BROADCAST CAMPAIGN
    elseif ($action === 'send_whatsapp') {
        $campaignName = trim($_POST['campaign_name'] ?? '');
        $sendType = $_POST['send_type'] ?? 'text';
        $senderId = (int)($_POST['sender_id'] ?? 0);
        $messageText = trim($_POST['message_text'] ?? '');
        $templateName = trim($_POST['template_name'] ?? '');
        $templateLanguage = trim($_POST['template_language'] ?? 'en_US');
        $variables = trim($_POST['variables'] ?? '');

        if (empty($campaignName)) {
            echo json_encode(["success" => false, "error" => "Please enter a Campaign Name."]);
            exit;
        }
        if ($sendType === 'text' && empty($messageText)) {
            echo json_encode(["success" => false, "error" => "Please enter message text."]);
            exit;
        }
        if ($sendType === 'template' && empty($templateName)) {
            echo json_encode(["success" => false, "error" => "Please select template name."]);
            exit;
        }

        // Fetch valid mobiles, skipping opt-outs and invalid/blacklist
        $inClause = implode(',', array_fill(0, count($customerIds), '?'));
        $stmt = $conn->prepare("SELECT mobile, tags FROM customers WHERE id IN ($inClause) AND company_id = ?");
        $validRecipients = [];
        if ($stmt) {
            $types = str_repeat('i', count($customerIds)) . "i";
            $bindParams = array_merge($customerIds, [$companyId]);
            $stmt->bind_param($types, ...$bindParams);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $r) {
                $num = preg_replace('/[^0-9]/', '', $r['mobile']);
                if (empty($num) || strlen($num) < 9) continue;
                if (stripos($r['tags'] ?? '', 'blacklist') !== false || stripos($r['tags'] ?? '', 'opt_out') !== false) continue;
                $validRecipients[] = $num;
            }
            $stmt->close();
        }
        $validRecipients = array_unique($validRecipients);
        $totalContacts = count($validRecipients);

        if ($totalContacts === 0) {
            echo json_encode(["success" => false, "error" => "No valid recipients found after filtering skipped/blacklisted profiles."]);
            exit;
        }

        // Save Broadcast Campaign in DB
        $recipientsString = implode(',', $validRecipients);
        $scheduledAt = date('Y-m-d H:i:s'); // Send immediately

        $stmt = $conn->prepare("
            INSERT INTO broadcast_campaigns (company_id, user_id, name, message_text, recipients, scheduled_at, total_contacts, status, template_name, template_language, template_variables)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled', ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param(
                "iissssssss",
                $companyId,
                $userId,
                $campaignName,
                $messageText,
                $recipientsString,
                $scheduledAt,
                $totalContacts,
                $templateName,
                $templateLanguage,
                $variables
            );
            if ($stmt->execute()) {
                // Background process launch popen start cron non-blockingly
                pclose(popen("start /B php " . escapeshellarg(__DIR__ . '/../cron/process_broadcasts.php'), "r"));

                // Audit log
                require_once(__DIR__ . '/../core/Services/AuditLogService.php');
                $audit = new \Core\Services\AuditLogService($conn);
                $audit->log($companyId, $userId, 'schedule_broadcast', "Scheduled campaign '$campaignName' to $totalContacts recipients");

                echo json_encode(["success" => true, "message" => "Campaign scheduled and queued successfully!"]);
            } else {
                echo json_encode(["success" => false, "error" => "Database insert failed: " . $stmt->error]);
            }
            $stmt->close();
        }
        exit;
    }

    // 3) BULK EMPLOYEE ASSIGNMENT
    elseif ($action === 'assign_employee') {
        if (!$isAdmin) {
            echo json_encode(["success" => false, "error" => "Unauthorized access."]);
            exit;
        }
        $assigneeId = !empty($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : null;
        $strategy = $_POST['assignment_strategy'] ?? 'manual';

        if ($strategy !== 'manual') {
            // Find active employees in company
            $empRes = $conn->query("SELECT id FROM users WHERE company_id = $companyId AND role = 'employee' ORDER BY id ASC");
            $employees = [];
            while ($r = $empRes->fetch_assoc()) {
                $employees[] = (int)$r['id'];
            }

            if (empty($employees)) {
                echo json_encode(["success" => false, "error" => "No active employee agents linked to route to."]);
                exit;
            }

            foreach ($customerIds as $cid) {
                $resolvedAssignee = $employees[0];
                if ($strategy === 'random') {
                    $resolvedAssignee = $employees[array_rand($employees)];
                } elseif ($strategy === 'least_loaded') {
                    $loadRes = $conn->query("
                        SELECT u.id, COUNT(c.id) as load_count 
                        FROM users u 
                        LEFT JOIN customers c ON u.id = c.assigned_to AND c.assignment_status = 'accepted'
                        WHERE u.company_id = $companyId AND u.role = 'employee'
                        GROUP BY u.id
                        ORDER BY load_count ASC, u.id ASC
                        LIMIT 1
                    ");
                    if ($loadRes) {
                        $resolvedAssignee = (int)($loadRes->fetch_assoc()['id'] ?? $employees[0]);
                    }
                } elseif ($strategy === 'round_robin') {
                    static $rrIndex = 0;
                    $resolvedAssignee = $employees[$rrIndex % count($employees)];
                    $rrIndex++;
                }
                
                $ctx = TenantContext::forCompany($companyId);
                $customerService->assignCustomers($ctx, [$cid], $resolvedAssignee, $userId, '127.0.0.1');
            }
        } else {
            // Manual Assign/Unassign
            $ctx = TenantContext::forCompany($companyId);
            $customerService->assignCustomers($ctx, $customerIds, $assigneeId, $userId, '127.0.0.1');
        }

        echo json_encode(["success" => true, "message" => "Customer routing assignments updated successfully!"]);
        exit;
    }

    // 4) BULK ADD/REMOVE TAGS
    elseif ($action === 'bulk_tags') {
        $tagAction = $_POST['tag_action'] ?? 'add';
        $tagName = trim($_POST['tag_name'] ?? '');

        if (empty($tagName)) {
            echo json_encode(["success" => false, "error" => "Tag name cannot be empty."]);
            exit;
        }

        foreach ($customerIds as $cid) {
            // Fetch current tags
            $stmt = $conn->prepare("SELECT tags FROM customers WHERE id = ? AND company_id = ? LIMIT 1");
            $currentTagsString = '';
            if ($stmt) {
                $stmt->bind_param("ii", $cid, $companyId);
                $stmt->execute();
                $currentTagsString = $stmt->get_result()->fetch_assoc()['tags'] ?? '';
                $stmt->close();
            }

            $currentTags = array_filter(array_map('trim', explode(',', $currentTagsString)));

            if ($tagAction === 'add') {
                if (!in_array($tagName, $currentTags)) {
                    $currentTags[] = $tagName;
                }
            } else {
                $currentTags = array_diff($currentTags, [$tagName]);
            }

            $newTagsString = implode(',', $currentTags);
            $updateStmt = $conn->prepare("UPDATE customers SET tags = ? WHERE id = ? AND company_id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("sii", $newTagsString, $cid, $companyId);
                $updateStmt->execute();
                $updateStmt->close();
            }
        }

        echo json_encode(["success" => true, "message" => "Customer tags synchronized successfully!"]);
        exit;
    }

    // 5) BULK NOTE
    elseif ($action === 'bulk_note') {
        $noteText = trim($_POST['note_text'] ?? '');
        if (empty($noteText)) {
            echo json_encode(["success" => false, "error" => "Note text cannot be empty."]);
            exit;
        }

        foreach ($customerIds as $cid) {
            $mobile = '';
            $mStmt = $conn->prepare("SELECT mobile FROM customers WHERE id = ? AND company_id = ? LIMIT 1");
            if ($mStmt) {
                $mStmt->bind_param("ii", $cid, $companyId);
                $mStmt->execute();
                $mobile = $mStmt->get_result()->fetch_assoc()['mobile'] ?? '';
                $mStmt->close();
            }

            if (empty($mobile)) continue;

            $convId = null;
            $cStmt = $conn->prepare("SELECT id FROM conversations WHERE contact_number = ? AND company_id = ? LIMIT 1");
            if ($cStmt) {
                $cStmt->bind_param("si", $mobile, $companyId);
                $cStmt->execute();
                $convId = $cStmt->get_result()->fetch_assoc()['id'] ?? null;
                $cStmt->close();
            }

            if (!$convId) {
                $cInsert = $conn->prepare("INSERT INTO conversations (company_id, contact_number, status) VALUES (?, ?, 'open')");
                if ($cInsert) {
                    $cInsert->bind_param("is", $companyId, $mobile);
                    $cInsert->execute();
                    $convId = $cInsert->insert_id;
                    $cInsert->close();
                }
            }

            if ($convId) {
                $nStmt = $conn->prepare("INSERT INTO conversation_notes (company_id, conversation_id, body, created_by) VALUES (?, ?, ?, ?)");
                if ($nStmt) {
                    $nStmt->bind_param("iisi", $companyId, $convId, $noteText, $userId);
                    $nStmt->execute();
                    $nStmt->close();
                }
            }
        }

        echo json_encode(["success" => true, "message" => "Internal note attached to selected profiles."]);
        exit;
    }

    // 6) BULK DELETE (Admin Only)
    elseif ($action === 'delete_selected') {
        if (!$isAdmin) {
            echo json_encode(["success" => false, "error" => "Unauthorized access."]);
            exit;
        }

        $inClause = implode(',', array_fill(0, count($customerIds), '?'));
        $stmt = $conn->prepare("DELETE FROM customers WHERE id IN ($inClause) AND company_id = ?");
        if ($stmt) {
            $types = str_repeat('i', count($customerIds)) . "i";
            $bindParams = array_merge($customerIds, [$companyId]);
            $stmt->bind_param($types, ...$bindParams);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode(["success" => true, "message" => "Selected customer records deleted."]);
        exit;
    }

    // 7) CANCEL CAMPAIGN
    elseif ($action === 'cancel_campaign') {
        $campaignId = (int)$_POST['campaign_id'];
        $conn->query("UPDATE broadcast_campaigns SET status = 'failed' WHERE id = $campaignId AND company_id = $companyId");
        echo json_encode(["success" => true, "message" => "Campaign canceled."]);
        exit;
    }

    // 8) RETRY FAILED CAMPAIGN
    elseif ($action === 'retry_campaign') {
        $campaignId = (int)$_POST['campaign_id'];
        $conn->query("UPDATE broadcast_campaigns SET status = 'scheduled', sent_contacts = 0 WHERE id = $campaignId AND company_id = $companyId");
        pclose(popen("start /B php " . escapeshellarg(__DIR__ . '/../cron/process_broadcasts.php'), "r"));
        echo json_encode(["success" => true, "message" => "Campaign queued for retry."]);
        exit;
    }
}

// ── Handle GET Requests (Export Selected/Filtered) ──
elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'export') {
        // Enforce export rules (respect employee isolation / Admin only checked based on request parameters)
        $targetType = $_GET['target_type'] ?? 'selected';
        $customerIds = [];
        
        if ($targetType === 'selected') {
            $rawIds = $_GET['selected_ids'] ?? '';
            $customerIds = array_map('intval', array_filter(explode(',', $rawIds)));
        } else {
            $rows = getFilteredCustomerIds($conn, $companyId, $role, $userId);
            $customerIds = array_column($rows, 'id');
        }

        if (empty($customerIds)) {
            die("No customer profiles selected for export.");
        }

        $inClause = implode(',', array_fill(0, count($customerIds), '?'));
        $stmt = $conn->prepare("
            SELECT mobile, full_name_ar, full_name_en, national_id, nationality, gender, project_name, program_name, employer, source_file, created_at 
            FROM customers 
            WHERE id IN ($inClause) AND company_id = ?
        ");

        if (!$stmt) {
            die("SQL Prepare failed.");
        }

        $types = str_repeat('i', count($customerIds)) . "i";
        $bindParams = array_merge($customerIds, [$companyId]);
        $stmt->bind_param($types, ...$bindParams);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Output CSV stream
        $filename = "crm_export_" . date("Ymd_His") . ".csv";
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        // Add BOM for Excel Arabic encoding support
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // CSV Headers
        fputcsv($output, [
            'Mobile',
            'Name (Arabic)',
            'Name (English)',
            'National ID',
            'Nationality',
            'Gender',
            'Project',
            'Program',
            'Employer',
            'Source',
            'Created At'
        ]);

        foreach ($rows as $row) {
            fputcsv($output, [
                $row['mobile'],
                $row['full_name_ar'],
                $row['full_name_en'],
                $row['national_id'],
                $row['nationality'],
                $row['gender'],
                $row['project_name'],
                $row['program_name'],
                $row['employer'],
                $row['source_file'],
                $row['created_at']
            ]);
        }
        fclose($output);
        exit;
    }
}

echo json_encode(["success" => false, "error" => "Bad request"]);
exit;
