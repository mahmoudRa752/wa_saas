<?php
/**
 * WA Manager — Export Deals Reports to Excel
 * File: api/export_deal_reports.php
 */
session_start();
require_once("../config/db.php");
require_once("../vendor/autoload.php");
require_once(__DIR__ . '/../core/TenantContext.php');
require_once(__DIR__ . '/../core/Deal/DealRepository.php');

use Core\TenantContext;
use Core\Deal\DealRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['company_id'])) {
    die("Access denied");
}

$ctx = TenantContext::fromSession();
$groupBy = $_GET['group_by'] ?? 'employee';

$dealRepo = new DealRepository($conn);
$data = $dealRepo->getReportData($ctx, $groupBy);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle("Sales Report");

// Set headers based on grouping
$labelHeader = match($groupBy) {
    'employee' => 'Employee Name',
    'project' => 'Project Name',
    'nationality' => 'Nationality',
    'program' => 'Program Name',
    'month' => 'Month (YYYY-MM)',
    default => 'Dimension'
};

$sheet->setCellValue('A1', $labelHeader);
$sheet->setCellValue('B1', 'Total Sales (SAR)');
$sheet->setCellValue('C1', 'Deals Won');

$sheet->getStyle('A1:C1')->getFont()->setBold(true);

$rowIndex = 2;
foreach ($data as $row) {
    $sheet->setCellValue('A' . $rowIndex, $row['label']);
    $sheet->setCellValue('B' . $rowIndex, (float)$row['val']);
    $sheet->setCellValue('C' . $rowIndex, (int)$row['cnt']);
    $rowIndex++;
}

// Add Total Row
if ($rowIndex > 2) {
    $sheet->setCellValue('A' . $rowIndex, 'Total');
    $sheet->setCellValue('B' . $rowIndex, '=SUM(B2:B' . ($rowIndex - 1) . ')');
    $sheet->setCellValue('C' . $rowIndex, '=SUM(C2:C' . ($rowIndex - 1) . ')');
    $sheet->getStyle('A' . $rowIndex . ':C' . $rowIndex)->getFont()->setBold(true);
}

// Auto size
foreach (range('A', 'C') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="sales_report_' . $groupBy . '_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
