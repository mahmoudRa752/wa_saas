<?php
require_once("vendor/autoload.php");

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// ✅ رؤوس الأعمدة
$sheet->setCellValue('A1', 'number');
$sheet->setCellValue('B1', 'name');
$sheet->setCellValue('C1', 'company');
$sheet->setCellValue('D1', 'message');

// ✅ مثال عربي
$sheet->setCellValue('A2', '9665XXXXXXX');
$sheet->setCellValue('B2', 'أحمد');
$sheet->setCellValue('C2', 'Porto Academy');
$sheet->setCellValue('D2', 'أهلاً {name}');

// ✅ تحميل
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="template.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;