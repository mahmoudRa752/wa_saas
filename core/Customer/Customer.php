<?php
/**
 * WA Manager — Customer Domain Entity Model
 * File: core/Customer/Customer.php
 */
namespace Core\Customer;

class Customer
{
    public function __construct(
        public ?int $id,
        public int $companyId,
        public ?string $fullNameAr,
        public ?string $fullNameEn,
        public string $mobile,
        public ?string $nationalId,
        public ?string $nationality,
        public ?string $gender,
        public ?string $projectName,
        public ?string $programName,
        public ?string $employer,
        public ?string $sourceFile,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?int $assignedTo = null,
        public ?int $assignedBy = null,
        public ?string $assignedAt = null,
        public ?string $assignmentStatus = 'unassigned'
    ) {}

    /**
     * Map database row to Customer entity instance
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int)$row['id'] : null,
            companyId: (int)$row['company_id'],
            fullNameAr: $row['full_name_ar'] ?? null,
            fullNameEn: $row['full_name_en'] ?? null,
            mobile: $row['mobile'],
            nationalId: $row['national_id'] ?? null,
            nationality: $row['nationality'] ?? null,
            gender: $row['gender'] ?? null,
            projectName: $row['project_name'] ?? null,
            programName: $row['program_name'] ?? null,
            employer: $row['employer'] ?? null,
            sourceFile: $row['source_file'] ?? null,
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null,
            assignedTo: isset($row['assigned_to']) ? (int)$row['assigned_to'] : null,
            assignedBy: isset($row['assigned_by']) ? (int)$row['assigned_by'] : null,
            assignedAt: $row['assigned_at'] ?? null,
            assignmentStatus: $row['assignment_status'] ?? 'unassigned'
        );
    }

    /**
     * Export object fields to array representation
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->companyId,
            'full_name_ar' => $this->fullNameAr,
            'full_name_en' => $this->fullNameEn,
            'mobile' => $this->mobile,
            'national_id' => $this->nationalId,
            'nationality' => $this->nationality,
            'gender' => $this->gender,
            'project_name' => $this->projectName,
            'program_name' => $this->programName,
            'employer' => $this->employer,
            'source_file' => $this->sourceFile,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'assigned_to' => $this->assignedTo,
            'assigned_by' => $this->assignedBy,
            'assigned_at' => $this->assignedAt,
            'assignment_status' => $this->assignmentStatus
        ];
    }
}
