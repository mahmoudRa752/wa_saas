<?php
/**
 * WA Manager — Export Customers to Excel
 * File: api/export_customers.php
 */
session_start();
require_once("../config/db.php");
require_once("../vendor/autoload.php");
require_once(__DIR__ . '/../core/TenantContext.php');
require_once(__DIR__ . '/../core/Customer/Customer.php');
require_once(__DIR__ . '/../core/Customer/CustomerRepository.php');

use Core\TenantContext;
use Core\Customer\CustomerRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['company_id']) || $_SESSION['role'] !== 'admin') {
    die("Access denied");
}

$ctx = TenantContext::fromSession();
$repository = new CustomerRepository($conn);

// 1. Resolve target lists (Selections or Filter criteria)
$customers = [];
if (!empty($_GET['ids'])) {
    $ids = array_map('intval', explode(',', $_GET['ids']));
    if (!empty($ids)) {
        // Fetch specific selected records scoped by tenant
        $inClause = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM customers WHERE company_id = ? AND id IN ($inClause)";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $types = 'i' . str_repeat('i', count($ids));
            $params = array_merge([$ctx->companyId], $ids);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $customers[] = \Core\Customer\Customer::fromRow($row);
            }
            $stmt->close();
        }
    }
} else {
    // Fetch filtered list
    $filters = [
        'gender' => $_GET['gender'] ?? null,
        'nationality' => $_GET['nationality'] ?? null,
        'employer' => $_GET['employer'] ?? null,
        'project_name' => $_GET['project_name'] ?? null,
        'program_name' => $_GET['program_name'] ?? null,
        'imported_date' => $_GET['imported_date'] ?? null,
        'national_id_status' => $_GET['national_id_status'] ?? null,
    ];
    $searchQuery = trim($_GET['q'] ?? '');
    $customers = $repository->getFilteredCustomers($ctx, $filters, $searchQuery);
}

// 2. Generate spreadsheet workbook
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Customers CRM");

// Setup Headers
$headers = [
    'A1' => 'Mobile',
    'B1' => 'Arabic Name',
    'C1' => 'English Name',
    'D1' => 'National ID',
    'E1' => 'Nationality',
    'F1' => 'Gender',
    'G1' => 'Employer',
    'H1' => 'Project',
    'I1' => 'Program',
    'J1' => 'Source File',
    'K1' => 'Created Date'
];

foreach ($headers as $cell => $val) {
    $sheet->setCellValue($cell, $val);
}
$sheet->getStyle('A1:K1')->getFont()->setBold(true);

// Fill Row Cells
$rowIndex = 2;
foreach ($customers as $cust) {
    $sheet->setCellValue('A' . $rowIndex, $cust->mobile);
    $sheet->setCellValue('B' . $rowIndex, $cust->fullNameAr);
    $sheet->setCellValue('C' . $rowIndex, $cust->fullNameEn);
    $sheet->setCellValue('D' . $rowIndex, $cust->nationalId);
    $sheet->setCellValue('E' . $rowIndex, $cust->nationality);
    $sheet->setCellValue('F' . $rowIndex, $cust->gender);
    $sheet->setCellValue('G' . $rowIndex, $cust->employer);
    $sheet->setCellValue('H' . $rowIndex, $cust->projectName);
    $sheet->setCellValue('I' . $rowIndex, $cust->programName);
    $sheet->setCellValue('J' . $rowIndex, $cust->sourceFile);
    $sheet->setCellValue('K' . $rowIndex, $cust->createdAt);
    $rowIndex++;
}

// Set Auto Column Widths
foreach (range('A', 'K') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Stream workbook download headers
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="customers_crm_export_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
