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
     * Fetch a paginated list of customers
     */
    public function getPaginatedCustomers(
        TenantContext $ctx,
        array $filters,
        string $searchQuery,
        int $page = 1,
        int $limit = 20,
        string $sortBy = 'created_at',
        string $sortOrder = 'DESC'
    ): array {
        $offset = ($page - 1) * $limit;
        $customers = $this->repository->searchAndFilter($ctx, $filters, $searchQuery, $limit, $offset, $sortBy, $sortOrder);
        $total = $this->repository->countSearchAndFilter($ctx, $filters, $searchQuery);
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
    public function getFilteredCustomerIds(TenantContext $ctx, array $filters, string $searchQuery): array
    {
        return $this->repository->getFilteredIds($ctx, $filters, $searchQuery);
    }

    /**
     * Fetch all customers matching criteria (for full data exports)
     */
    public function getFilteredCustomers(TenantContext $ctx, array $filters, string $searchQuery): array
    {
        return $this->repository->getFilteredCustomers($ctx, $filters, $searchQuery);
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
                    updatedAt: null
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
}
