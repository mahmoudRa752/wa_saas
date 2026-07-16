<?php
/**
 * WA Manager — Sales Pipeline Reports
 * File: dashboard/deal_reports.php
 */
session_start();
require_once("../config/db.php");
require_once("../vendor/autoload.php");
require_once(__DIR__ . '/../core/TenantContext.php');
require_once(__DIR__ . '/../core/Deal/DealRepository.php');

use Core\TenantContext;
use Core\Deal\DealRepository;

if (!isset($_SESSION['company_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$ctx = TenantContext::fromSession();
$dealRepo = new DealRepository($conn);

// Query all report groupings
$empReport = $dealRepo->getReportData($ctx, 'employee');
$projectReport = $dealRepo->getReportData($ctx, 'project');
$nationalityReport = $dealRepo->getReportData($ctx, 'nationality');
$programReport = $dealRepo->getReportData($ctx, 'program');
$monthReport = $dealRepo->getReportData($ctx, 'month');

include("../layouts/header.php");
?>

<div class="card p-3 shadow-sm border-0 mb-4 bg-light">
    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-graph-up-arrow text-primary me-2"></i>Sales Performance Reports</h5>
    <small class="text-secondary">Review aggregated sales revenue and deal counts grouped by different CRM segments.</small>
</div>

<div class="row g-4">
    <!-- 1. Employee Performance Report -->
    <div class="col-md-6">
        <div class="card p-3 shadow-sm border-0 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-people me-2 text-primary"></i>Sales by Employee</h6>
                <a href="../api/export_deal_reports.php?group_by=employee" class="btn btn-outline-success btn-xs fw-semibold"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm" style="font-size:12.5px;">
                    <thead class="table-light">
                        <tr>
                            <th>Employee Name</th>
                            <th class="text-end">Total Won Sales</th>
                            <th class="text-center">Deals Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($empReport)): ?>
                            <tr><td colspan="3" class="text-center text-muted">No sales logged.</td></tr>
                        <?php else: ?>
                            <?php foreach ($empReport as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                                    <td class="text-end text-success fw-bold"><?php echo number_format($row['val'], 2); ?> SAR</td>
                                    <td class="text-center"><?php echo $row['cnt']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 2. Project Sales Report -->
    <div class="col-md-6">
        <div class="card p-3 shadow-sm border-0 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-kanban me-2 text-warning"></i>Sales by Project</h6>
                <a href="../api/export_deal_reports.php?group_by=project" class="btn btn-outline-success btn-xs fw-semibold"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm" style="font-size:12.5px;">
                    <thead class="table-light">
                        <tr>
                            <th>Project Name</th>
                            <th class="text-end">Total Won Sales</th>
                            <th class="text-center">Deals Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($projectReport)): ?>
                            <tr><td colspan="3" class="text-center text-muted">No sales logged.</td></tr>
                        <?php else: ?>
                            <?php foreach ($projectReport as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                                    <td class="text-end text-success fw-bold"><?php echo number_format($row['val'], 2); ?> SAR</td>
                                    <td class="text-center"><?php echo $row['cnt']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 3. Nationality Sales Report -->
    <div class="col-md-6">
        <div class="card p-3 shadow-sm border-0 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-globe me-2 text-info"></i>Sales by Nationality</h6>
                <a href="../api/export_deal_reports.php?group_by=nationality" class="btn btn-outline-success btn-xs fw-semibold"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm" style="font-size:12.5px;">
                    <thead class="table-light">
                        <tr>
                            <th>Nationality</th>
                            <th class="text-end">Total Won Sales</th>
                            <th class="text-center">Deals Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($nationalityReport)): ?>
                            <tr><td colspan="3" class="text-center text-muted">No sales logged.</td></tr>
                        <?php else: ?>
                            <?php foreach ($nationalityReport as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                                    <td class="text-end text-success fw-bold"><?php echo number_format($row['val'], 2); ?> SAR</td>
                                    <td class="text-center"><?php echo $row['cnt']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 4. Program Sales Report -->
    <div class="col-md-6">
        <div class="card p-3 shadow-sm border-0 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-mortarboard me-2 text-danger"></i>Sales by Program</h6>
                <a href="../api/export_deal_reports.php?group_by=program" class="btn btn-outline-success btn-xs fw-semibold"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm" style="font-size:12.5px;">
                    <thead class="table-light">
                        <tr>
                            <th>Program Name</th>
                            <th class="text-end">Total Won Sales</th>
                            <th class="text-center">Deals Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($programReport)): ?>
                            <tr><td colspan="3" class="text-center text-muted">No sales logged.</td></tr>
                        <?php else: ?>
                            <?php foreach ($programReport as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                                    <td class="text-end text-success fw-bold"><?php echo number_format($row['val'], 2); ?> SAR</td>
                                    <td class="text-center"><?php echo $row['cnt']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- 5. Monthly Trend Sales Report -->
    <div class="col-md-12">
        <div class="card p-3 shadow-sm border-0">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="fw-bold text-dark mb-0"><i class="bi bi-calendar-event me-2 text-success"></i>Monthly Sales Trend</h6>
                <a href="../api/export_deal_reports.php?group_by=month" class="btn btn-outline-success btn-xs fw-semibold"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm" style="font-size:12.5px;">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th class="text-end">Total Won Sales</th>
                            <th class="text-center">Deals Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monthReport)): ?>
                            <tr><td colspan="3" class="text-center text-muted">No monthly sales logged.</td></tr>
                        <?php else: ?>
                            <?php foreach ($monthReport as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($row['label']); ?></strong></td>
                                    <td class="text-end text-success fw-bold"><?php echo number_format($row['val'], 2); ?> SAR</td>
                                    <td class="text-center"><?php echo $row['cnt']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include("../layouts/footer.php"); ?>
