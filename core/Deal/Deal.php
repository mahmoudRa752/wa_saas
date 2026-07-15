<?php
/**
 * WA Manager — Sales Deal Entity Model
 * File: core/Deal/Deal.php
 */
namespace Core\Deal;

class Deal
{
    public function __construct(
        public ?int $id,
        public int $companyId,
        public int $customerId,
        public ?int $assignedTo,
        public int $stageId,
        public float $estimatedValue,
        public string $currency,
        public int $probability,
        public ?string $expectedCloseDate,
        public ?string $source,
        public ?string $notes,
        public ?int $createdBy,
        public ?string $createdAt,
        public ?string $updatedAt
    ) {}

    /**
     * Map database row to Deal entity instance
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int)$row['id'] : null,
            companyId: (int)$row['company_id'],
            customerId: (int)$row['customer_id'],
            assignedTo: isset($row['assigned_to']) ? (int)$row['assigned_to'] : null,
            stageId: (int)$row['stage_id'],
            estimatedValue: (float)($row['estimated_value'] ?? 0.0),
            currency: $row['currency'] ?? 'SAR',
            probability: (int)($row['probability'] ?? 0),
            expectedCloseDate: $row['expected_close_date'] ?? null,
            source: $row['source'] ?? null,
            notes: $row['notes'] ?? null,
            createdBy: isset($row['created_by']) ? (int)$row['created_by'] : null,
            createdAt: $row['created_at'] ?? null,
            updatedAt: $row['updated_at'] ?? null
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
            'customer_id' => $this->customerId,
            'assigned_to' => $this->assignedTo,
            'stage_id' => $this->stageId,
            'estimated_value' => $this->estimatedValue,
            'currency' => $this->currency,
            'probability' => $this->probability,
            'expected_close_date' => $this->expectedCloseDate,
            'source' => $this->source,
            'notes' => $this->notes,
            'created_by' => $this->createdBy,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}
