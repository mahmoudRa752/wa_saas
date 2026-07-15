<?php
/**
 * WA Manager — Customer Database Repository
 * File: core/Customer/CustomerRepository.php
 */
namespace Core\Customer;

use Core\TenantContext;
use mysqli;

class CustomerRepository
{
    public function __construct(private readonly mysqli $db) {}

    /**
     * Get a customer by ID with tenant isolation guard
     */
    public function findById(int $id, TenantContext $ctx): ?Customer
    {
        $companyId = $ctx->companyId;
        $stmt = $this->db->prepare(
            'SELECT * FROM customers WHERE id = ? AND company_id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $id, $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? Customer::fromRow($row) : null;
    }

    /**
     * Get a customer by Mobile with tenant isolation guard
     */
    public function findByMobile(TenantContext $ctx, string $mobile): ?Customer
    {
        $companyId = $ctx->companyId;
        $stmt = $this->db->prepare(
            'SELECT * FROM customers WHERE company_id = ? AND mobile = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('is', $companyId, $mobile);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? Customer::fromRow($row) : null;
    }

    /**
     * Insert a new customer record
     */
    public function create(Customer $customer): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO customers (
                company_id, full_name_ar, full_name_en, mobile, national_id,
                nationality, gender, project_name, program_name, employer, source_file
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param(
            'issssssssss',
            $customer->companyId,
            $customer->fullNameAr,
            $customer->fullNameEn,
            $customer->mobile,
            $customer->nationalId,
            $customer->nationality,
            $customer->gender,
            $customer->projectName,
            $customer->programName,
            $customer->employer,
            $customer->sourceFile
        );

        $stmt->execute();
        $insertId = $stmt->insert_id;
        $stmt->close();

        return $insertId;
    }

    /**
     * Update an existing customer record
     */
    public function update(Customer $customer): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE customers SET
                full_name_ar = ?,
                full_name_en = ?,
                mobile = ?,
                national_id = ?,
                nationality = ?,
                gender = ?,
                project_name = ?,
                program_name = ?,
                employer = ?,
                source_file = ?
             WHERE id = ? AND company_id = ?'
        );
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ssssssssssii',
            $customer->fullNameAr,
            $customer->fullNameEn,
            $customer->mobile,
            $customer->nationalId,
            $customer->nationality,
            $customer->gender,
            $customer->projectName,
            $customer->programName,
            $customer->employer,
            $customer->sourceFile,
            $customer->id,
            $customer->companyId
        );

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Delete a customer record
     */
    public function delete(int $id, TenantContext $ctx): bool
    {
        $companyId = $ctx->companyId;
        $stmt = $this->db->prepare(
            'DELETE FROM customers WHERE id = ? AND company_id = ?'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $id, $companyId);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Fetch a paginated list of customers scoped by search filters
     */
    public function searchAndFilter(
        TenantContext $ctx,
        array $filters,
        string $searchQuery,
        int $limit,
        int $offset,
        string $sortBy = 'created_at',
        string $sortOrder = 'DESC'
    ): array {
        list($sql, $types, $params) = $this->buildQuery($ctx, $filters, $searchQuery, false);

        // Sanitize sorting inputs
        $allowedSorts = ['created_at', 'mobile', 'full_name_ar', 'full_name_en', 'national_id', 'nationality', 'gender', 'project_name', 'program_name', 'employer'];
        if (!in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

        $sql .= " ORDER BY `$sortBy` $sortOrder LIMIT ? OFFSET ?";
        $types .= 'ii';
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $customers = [];
        while ($row = $result->fetch_assoc()) {
            $customers[] = Customer::fromRow($row);
        }
        $stmt->close();

        return $customers;
    }

    /**
     * Get count of matching customer search results
     */
    public function countSearchAndFilter(TenantContext $ctx, array $filters, string $searchQuery): int
    {
        list($sql, $types, $params) = $this->buildQuery($ctx, $filters, $searchQuery, true);

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();

        return (int)($row[0] ?? 0);
    }

    /**
     * Get list of all matching customer IDs under a filter (for bulk action on entire search results)
     */
    public function getFilteredIds(TenantContext $ctx, array $filters, string $searchQuery): array
    {
        list($sql, $types, $params) = $this->buildQuery($ctx, $filters, $searchQuery, false);
        $sql = "SELECT id " . strstr($sql, "FROM customers");

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $ids = [];
        while ($row = $result->fetch_row()) {
            $ids[] = (int)$row[0];
        }
        $stmt->close();

        return $ids;
    }

    /**
     * Fetch all customers matching criteria (useful for full export)
     */
    public function getFilteredCustomers(TenantContext $ctx, array $filters, string $searchQuery): array
    {
        list($sql, $types, $params) = $this->buildQuery($ctx, $filters, $searchQuery, false);
        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }

        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $customers = [];
        while ($row = $result->fetch_assoc()) {
            $customers[] = Customer::fromRow($row);
        }
        $stmt->close();

        return $customers;
    }

    /**
     * Get distinct values of a column (for populating filter selectors)
     */
    public function getDistinctFieldValues(TenantContext $ctx, string $field): array
    {
        $companyId = $ctx->companyId;
        $allowedFields = ['gender', 'nationality', 'employer', 'project_name', 'program_name'];
        if (!in_array($field, $allowedFields)) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT DISTINCT `$field` FROM customers WHERE company_id = ? AND `$field` IS NOT NULL AND TRIM(`$field`) != '' ORDER BY `$field` ASC"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $result = $stmt->get_result();
        $values = [];
        while ($row = $result->fetch_row()) {
            $values[] = $row[0];
        }
        $stmt->close();

        return $values;
    }

    /**
     * Query builder helper sharing common filters
     */
    private function buildQuery(TenantContext $ctx, array $filters, string $searchQuery, bool $isCount = false): array
    {
        $companyId = $ctx->companyId;
        $select = $isCount ? "SELECT COUNT(*)" : "SELECT *";
        $sql = "$select FROM customers WHERE company_id = ?";
        $types = "i";
        $params = [$companyId];

        if (!empty($searchQuery)) {
            $wildcard = '%' . $searchQuery . '%';
            $sql .= " AND (mobile LIKE ? OR full_name_ar LIKE ? OR full_name_en LIKE ? OR national_id LIKE ? OR employer LIKE ? OR project_name LIKE ? OR program_name LIKE ?)";
            $types .= "sssssss";
            for ($i = 0; $i < 7; $i++) {
                $params[] = $wildcard;
            }
        }

        if (!empty($filters['gender'])) {
            $sql .= " AND gender = ?";
            $types .= "s";
            $params[] = $filters['gender'];
        }

        if (!empty($filters['nationality'])) {
            $sql .= " AND nationality = ?";
            $types .= "s";
            $params[] = $filters['nationality'];
        }

        if (!empty($filters['employer'])) {
            $sql .= " AND employer = ?";
            $types .= "s";
            $params[] = $filters['employer'];
        }

        if (!empty($filters['project_name'])) {
            $sql .= " AND project_name = ?";
            $types .= "s";
            $params[] = $filters['project_name'];
        }

        if (!empty($filters['program_name'])) {
            $sql .= " AND program_name = ?";
            $types .= "s";
            $params[] = $filters['program_name'];
        }

        if (!empty($filters['imported_date'])) {
            $sql .= " AND DATE(created_at) = ?";
            $types .= "s";
            $params[] = $filters['imported_date'];
        }

        if (isset($filters['national_id_status'])) {
            if ($filters['national_id_status'] === 'missing') {
                $sql .= " AND (national_id IS NULL OR TRIM(national_id) = '')";
            } elseif ($filters['national_id_status'] === 'has') {
                $sql .= " AND (national_id IS NOT NULL AND TRIM(national_id) != '')";
            }
        }

        return [$sql, $types, $params];
    }
}
