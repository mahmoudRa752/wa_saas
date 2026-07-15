<?php
/**
 * WA Manager — Customer CRM Service
 * File: core/Customer/CustomerService.php
 */
namespace Core\Customer;

use Core\TenantContext;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CustomerService
{
    public function __construct(private readonly CustomerRepository $repository) {}

    /**
     * Fetch a paginated list of customers scoped by employee isolation checks
     */
    public function getPaginatedCustomers(
        TenantContext $ctx,
        array $filters,
        string $searchQuery,
        int $page = 1,
        int $limit = 20,
        string $sortBy = 'created_at',
        string $sortOrder = 'DESC',
        ?string $role = null,
        ?int $userId = null
    ): array {
        $offset = ($page - 1) * $limit;
        $customers = $this->repository->searchAndFilter($ctx, $filters, $searchQuery, $limit, $offset, $sortBy, $sortOrder, $role, $userId);
        $total = $this->repository->countSearchAndFilter($ctx, $filters, $searchQuery, $role, $userId);
        $pages = (int)ceil($total / $limit);

        return [
            'data' => $customers,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => $pages
        ];
    }

    /**
     * Get distinct values for advanced filters
     */
    public function getFilterDropdowns(TenantContext $ctx): array
    {
        return [
            'genders' => $this->repository->getDistinctFieldValues($ctx, 'gender'),
            'nationalities' => $this->repository->getDistinctFieldValues($ctx, 'nationality'),
            'employers' => $this->repository->getDistinctFieldValues($ctx, 'employer'),
            'projects' => $this->repository->getDistinctFieldValues($ctx, 'project_name'),
            'programs' => $this->repository->getDistinctFieldValues($ctx, 'program_name'),
        ];
    }

    /**
     * Create or edit a single customer manually
     */
    public function saveCustomer(TenantContext $ctx, Customer $customer): array
    {
        // Enforce tenant context safety
        $customer->companyId = $ctx->companyId;

        // Clean and validate mobile
        $cleanMobile = preg_replace('/[^0-9]/', '', $customer->mobile);
        if (strlen($cleanMobile) < 7) {
            return ['success' => false, 'error' => 'Invalid mobile number (must be at least 7 digits)'];
        }
        $customer->mobile = $cleanMobile;

        // Check if unique constraint violated
        $existing = $this->repository->findByMobile($ctx, $customer->mobile);
        if ($customer->id === null) {
            // Create Flow
            if ($existing) {
                return ['success' => false, 'error' => 'Customer with this mobile number already exists in this company'];
            }
            $newId = $this->repository->create($customer);
            return ['success' => true, 'id' => $newId];
        } else {
            // Edit Flow
            if ($existing && $existing->id !== $customer->id) {
                return ['success' => false, 'error' => 'Another customer with this mobile number already exists'];
            }
            
            // Retain old assignment properties on edit to prevent wipeouts
            if ($existing) {
                $customer->assignedTo = $existing->assignedTo;
                $customer->assignedBy = $existing->assignedBy;
                $customer->assignedAt = $existing->assignedAt;
                $customer->assignmentStatus = $existing->assignmentStatus;
            }

            $success = $this->repository->update($customer);
            return ['success' => $success];
        }
    }

    /**
     * Delete customer
     */
    public function deleteCustomer(TenantContext $ctx, int $id): bool
    {
        return $this->repository->delete($id, $ctx);
    }

    /**
     * Fetch list of IDs matching the filters (for bulk selections)
     */
    public function getFilteredCustomerIds(TenantContext $ctx, array $filters, string $searchQuery, ?string $role = null, ?int $userId = null): array
    {
        return $this->repository->getFilteredIds($ctx, $filters, $searchQuery, $role, $userId);
    }

    /**
     * Fetch all customers matching criteria (for full data exports)
     */
    public function getFilteredCustomers(TenantContext $ctx, array $filters, string $searchQuery, ?string $role = null, ?int $userId = null): array
    {
        return $this->repository->getFilteredCustomers($ctx, $filters, $searchQuery, $role, $userId);
    }

    /**
     * Assign, reassign, or unassign customers in bulk and log the action
     */
    public function assignCustomers(
        TenantContext $ctx,
        array $customerIds,
        ?int $assignedTo,
        ?int $assignedBy,
        string $adminIp
    ): bool {
        $status = $assignedTo ? 'pending' : 'unassigned';
        $assignedAt = $assignedTo ? date('Y-m-d H:i:s') : null;

        $this->repository->bulkUpdateAssignment($ctx, $customerIds, $assignedTo, $assignedBy, $assignedAt, $status);

        // Resolve names for Audit Log Description
        $employeeName = 'Unassigned';
        if ($assignedTo) {
            $employeeName = $this->getEmployeeNameById($assignedTo);
        }
        $count = count($customerIds);
        $action = $assignedTo ? 'assign_customers' : 'unassign_customers';
        $description = "Admin assigned $count customers to Employee '$employeeName'. IP: $adminIp";
        if (!$assignedTo) {
            $description = "Admin unassigned $count customers. IP: $adminIp";
        }

        $audit = new \Core\Services\AuditLogService($this->repository->getDbConnection());
        $audit->log($ctx->companyId, $assignedBy, $action, $description);

        if ($assignedTo) {
            $this->createNotification($ctx->companyId, $assignedTo, 'assignment_pending', "You have a new customer assignment: $count contacts. Please Accept or Reject.");
        }

        return true;
    }

    /**
     * Accept assignments and update customer records
     */
    public function acceptAssignments(TenantContext $ctx, int $employeeId, array $customerIds): bool
    {
        return $this->repository->updateAssignmentStatus($ctx, $employeeId, $customerIds, 'accepted');
    }

    /**
     * Reject assignments and return them to the Admin queue (i.e. 'rejected')
     */
    public function rejectAssignments(TenantContext $ctx, int $employeeId, array $customerIds): bool
    {
        $res = $this->repository->updateAssignmentStatus($ctx, $employeeId, $customerIds, 'rejected');
        if ($res) {
            $db = $this->repository->getDbConnection();
            $adminRes = $db->query("SELECT id FROM users WHERE company_id = {$ctx->companyId} AND role = 'admin'");
            if ($adminRes) {
                $employeeName = $this->getEmployeeNameById($employeeId);
                $count = count($customerIds);
                while ($row = $adminRes->fetch_assoc()) {
                    $adminId = (int)$row['id'];
                    $this->createNotification($ctx->companyId, $adminId, 'assignment_rejected', "Employee '$employeeName' rejected $count customer assignments.");
                }
            }
        }
        return $res;
    }

    /**
     * Import customers from Excel (XLSX, XLS, CSV)
     */
    public function importFromFile(TenantContext $ctx, string $filePath, string $originalFileName): array
    {
        $companyId = $ctx->companyId;
        try {
            $spreadsheet = IOFactory::load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Failed to parse file: ' . $e->getMessage()];
        }

        if (empty($rows)) {
            return ['success' => false, 'error' => 'The uploaded file is empty.'];
        }

        $headers = array_map(function($val) {
            return $val !== null ? trim($val) : '';
        }, $rows[0]);

        $colMapping = $this->mapHeaders($headers);

        if (!isset($colMapping['mobile'])) {
            return ['success' => false, 'error' => 'Could not detect any mobile number column. Columns mapped must include a mobile/phone indicator.'];
        }

        $totalRows = count($rows) - 1; // Exclude header row
        $importedCount = 0;
        $updatedCount = 0;
        $skippedCount = 0;
        $duplicateCount = 0;
        $invalidCount = 0;

        $seenMobiles = [];
        $sourceFile = basename($originalFileName);

        foreach ($rows as $index => $row) {
            if ($index === 0) {
                continue; // Skip header
            }

            $nonEmptyCells = array_filter($row, function($cell) {
                return $cell !== null && trim((string)$cell) !== '';
            });
            if (empty($nonEmptyCells)) {
                $skippedCount++;
                continue;
            }

            $mobileVal = $row[$colMapping['mobile']] ?? '';
            $cleanMobile = preg_replace('/[^0-9]/', '', (string)$mobileVal);

            if (strlen($cleanMobile) < 7) {
                $invalidCount++;
                $skippedCount++;
                continue;
            }

            if (in_array($cleanMobile, $seenMobiles)) {
                $duplicateCount++;
                $skippedCount++;
                continue;
            }
            $seenMobiles[] = $cleanMobile;

            $fullNameAr = $colMapping['full_name_ar'] !== null ? trim((string)($row[$colMapping['full_name_ar']] ?? '')) : null;
            $fullNameEn = $colMapping['full_name_en'] !== null ? trim((string)($row[$colMapping['full_name_en']] ?? '')) : null;
            $nationalId = $colMapping['national_id'] !== null ? trim((string)($row[$colMapping['national_id']] ?? '')) : null;
            $nationality = $colMapping['nationality'] !== null ? trim((string)($row[$colMapping['nationality']] ?? '')) : null;
            $gender      = $colMapping['gender'] !== null ? trim((string)($row[$colMapping['gender']] ?? '')) : null;
            $projectName = $colMapping['project_name'] !== null ? trim((string)($row[$colMapping['project_name']] ?? '')) : null;
            $programName = $colMapping['program_name'] !== null ? trim((string)($row[$colMapping['program_name']] ?? '')) : null;
            $employer    = $colMapping['employer'] !== null ? trim((string)($row[$colMapping['employer']] ?? '')) : null;

            $existing = $this->repository->findByMobile($ctx, $cleanMobile);

            if ($existing) {
                if ($fullNameAr !== null && $fullNameAr !== '') {
                    $existing->fullNameAr = $fullNameAr;
                }
                if ($fullNameEn !== null && $fullNameEn !== '') {
                    $existing->fullNameEn = $fullNameEn;
                }
                if ($nationalId !== null && $nationalId !== '') {
                    $existing->nationalId = $nationalId;
                }
                if ($nationality !== null && $nationality !== '') {
                    $existing->nationality = $nationality;
                }
                if ($gender !== null && $gender !== '') {
                    $existing->gender = $gender;
                }
                if ($projectName !== null && $projectName !== '') {
                    $existing->projectName = $projectName;
                }
                if ($programName !== null && $programName !== '') {
                    $existing->programName = $programName;
                }
                if ($employer !== null && $employer !== '') {
                    $existing->employer = $employer;
                }
                $existing->sourceFile = $sourceFile;

                $this->repository->update($existing);
                $updatedCount++;
            } else {
                $customer = new Customer(
                    id: null,
                    companyId: $companyId,
                    fullNameAr: $fullNameAr !== '' ? $fullNameAr : null,
                    fullNameEn: $fullNameEn !== '' ? $fullNameEn : null,
                    mobile: $cleanMobile,
                    nationalId: $nationalId !== '' ? $nationalId : null,
                    nationality: $nationality !== '' ? $nationality : null,
                    gender: $gender !== '' ? $gender : null,
                    projectName: $projectName !== '' ? $projectName : null,
                    programName: $programName !== '' ? $programName : null,
                    employer: $employer !== '' ? $employer : null,
                    sourceFile: $sourceFile,
                    createdAt: null,
                    updatedAt: null,
                    assignedTo: null,
                    assignedBy: null,
                    assignedAt: null,
                    assignmentStatus: 'unassigned'
                );
                $this->repository->create($customer);
                $importedCount++;
            }
        }

        return [
            'success' => true,
            'total_rows' => $totalRows,
            'imported' => $importedCount,
            'updated' => $updatedCount,
            'skipped' => $skippedCount,
            'duplicates' => $duplicateCount,
            'invalid_mobiles' => $invalidCount
        ];
    }

    /**
     * Query employee name by ID
     */
    private function getEmployeeNameById(int $id): string
    {
        $db = $this->repository->getDbConnection();
        $stmt = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row['name'] ?? 'Unknown Employee';
        }
        return 'Unknown Employee';
    }

    /**
     * Map header labels to database fields using fuzzy substring matches
     */
    private function mapHeaders(array $headers): array
    {
        $mapping = [
            'mobile' => null,
            'full_name_ar' => null,
            'full_name_en' => null,
            'national_id' => null,
            'nationality' => null,
            'gender' => null,
            'project_name' => null,
            'program_name' => null,
            'employer' => null
        ];

        foreach ($headers as $index => $rawHeader) {
            if ($rawHeader === null || trim((string)$rawHeader) === '') {
                continue;
            }
            $header = trim(mb_strtolower((string)$rawHeader, 'UTF-8'));
            $header = preg_replace('/[\s_\-]+/', ' ', $header);

            if (self::matchesKeyword($header, ['جوال', 'هاتف', 'تليفون', 'mobile', 'phone', 'cell'])) {
                $mapping['mobile'] = $index;
            } elseif (self::matchesKeyword($header, ['اسم عربي', 'اسم بالكامل عربي', 'كامل باللغة العربية', 'الاسم بالعربية', 'الاسم عربي', 'name_ar', 'name ar', 'arabic'])) {
                $mapping['full_name_ar'] = $index;
            } elseif (self::matchesKeyword($header, ['اسم انجليز', 'الاسم بالكامل انجليزي', 'الاسم بالانجليزية', 'الاسم انجليزي', 'name_en', 'name en', 'english', 'eng'])) {
                $mapping['full_name_en'] = $index;
            } elseif (self::matchesKeyword($header, ['هوية', 'اقامة', 'إقامة', 'الاقامة', 'national_id', 'national id', 'iqama', 'residence'])) {
                $mapping['national_id'] = $index;
            } elseif (self::matchesKeyword($header, ['جنسية', 'nationality'])) {
                $mapping['nationality'] = $index;
            } elseif (self::matchesKeyword($header, ['الجنس', 'النوع', 'gender', 'sex'])) {
                $mapping['gender'] = $index;
            } elseif (self::matchesKeyword($header, ['المشروع', 'project'])) {
                $mapping['project_name'] = $index;
            } elseif (self::matchesKeyword($header, ['البرنامج', 'program'])) {
                $mapping['program_name'] = $index;
            } elseif (self::matchesKeyword($header, ['جهة العمل', 'جهة التوظيف', 'صاحب العمل', 'الجهة', 'الشركة', 'employer', 'company'])) {
                $mapping['employer'] = $index;
            }
        }

        return $mapping;
    }

    private static function matchesKeyword(string $header, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (mb_strpos($header, $kw) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Automatic Customer Routing on incoming WhatsApp messages
     */
    public function routeIncomingCustomer(int $companyId, string $mobile): ?int
    {
        $ctx = TenantContext::forCompany($companyId);
        $existing = $this->repository->findByMobile($ctx, $mobile);
        if ($existing) {
            return $existing->id;
        }

        $db = $this->repository->getDbConnection();
        $stmt = $db->prepare("SELECT auto_assignment_mode FROM companies WHERE id = ? LIMIT 1");
        $mode = 'manual';
        if ($stmt) {
            $stmt->bind_param("i", $companyId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $mode = $row['auto_assignment_mode'] ?? 'manual';
            $stmt->close();
        }

        $assignedTo = null;
        $status = 'unassigned';

        if ($mode !== 'manual') {
            $empRes = $db->query("SELECT id FROM users WHERE company_id = $companyId AND role = 'employee' ORDER BY id ASC");
            $employees = [];
            while ($r = $empRes->fetch_assoc()) {
                $employees[] = (int)$r['id'];
            }

            if (!empty($employees)) {
                if ($mode === 'random') {
                    $assignedTo = $employees[array_rand($employees)];
                } elseif ($mode === 'least_loaded') {
                    $loadRes = $db->query("
                        SELECT u.id, COUNT(c.id) as load_count 
                        FROM users u 
                        LEFT JOIN customers c ON u.id = c.assigned_to AND c.assignment_status = 'accepted'
                        WHERE u.company_id = $companyId AND u.role = 'employee'
                        GROUP BY u.id
                        ORDER BY load_count ASC, u.id ASC
                        LIMIT 1
                    ");
                    if ($loadRes) {
                        $assignedTo = (int)($loadRes->fetch_assoc()['id'] ?? $employees[0]);
                    } else {
                        $assignedTo = $employees[0];
                    }
                } elseif ($mode === 'round_robin') {
                    $lastAssRes = $db->query("SELECT assigned_to FROM customers WHERE company_id = $companyId AND assigned_to IS NOT NULL ORDER BY assigned_at DESC LIMIT 1");
                    $lastAssignedTo = $lastAssRes ? (int)($lastAssRes->fetch_row()[0] ?? 0) : 0;
                    
                    $assignedTo = $employees[0];
                    if ($lastAssignedTo > 0) {
                        $index = array_search($lastAssignedTo, $employees);
                        if ($index !== false && isset($employees[$index + 1])) {
                            $assignedTo = $employees[$index + 1];
                        }
                    }
                }
                
                if ($assignedTo) {
                    $status = 'pending';
                }
            }
        }

        $customer = new Customer(
            id: null,
            companyId: $companyId,
            fullNameAr: 'New Lead',
            fullNameEn: 'New Lead',
            mobile: $mobile,
            nationalId: null,
            nationality: null,
            gender: null,
            projectName: null,
            programName: null,
            employer: null,
            sourceFile: 'WhatsApp Inbound',
            createdAt: null,
            updatedAt: null,
            assignedTo: $assignedTo,
            assignedBy: null,
            assignedAt: $assignedTo ? date('Y-m-d H:i:s') : null,
            assignmentStatus: $status
        );

        $newId = $this->repository->create($customer);

        $action = $assignedTo ? 'auto_route_customer' : 'inbound_lead_received';
        $employeeName = $assignedTo ? $this->getEmployeeNameById($assignedTo) : 'None (Unassigned)';
        $description = "Inbound contact $mobile received. Strategy '$mode' selected. Route destination: $employeeName.";
        
        $audit = new \Core\Services\AuditLogService($db);
        $audit->log($companyId, null, $action, $description);

        if ($assignedTo) {
            $this->createNotification($companyId, $assignedTo, 'assignment_pending', "You have a new customer assigned automatically: $mobile. Please Accept or Reject.");
        }

        return $newId;
    }

    /**
     * Helper to store custom routing notifications
     */
    private function createNotification(int $companyId, int $userId, string $type, string $message): void
    {
        $db = $this->repository->getDbConnection();
        $stmt = $db->prepare("
            INSERT INTO notifications (company_id, user_id, type, message) 
            VALUES (?, ?, ?, ?)
        ");
        if ($stmt) {
            $stmt->bind_param("iiss", $companyId, $userId, $type, $message);
            $stmt->execute();
            $stmt->close();
        }
    }
}
