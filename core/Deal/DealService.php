<?php
/**
 * WA Manager — Sales Deals CRM Service
 * File: core/Deal/DealService.php
 */
namespace Core\Deal;

use Core\TenantContext;
use Core\Customer\CustomerRepository;

class DealService
{
    public function __construct(
        private readonly DealRepository $repository,
        private readonly CustomerRepository $customerRepository
    ) {}

    /**
     * Fetch stages and seed defaults if none exist
     */
    public function getStagesAndSeed(TenantContext $ctx): array
    {
        $stages = $this->repository->getStages($ctx);
        if (empty($stages)) {
            $defaults = ['New Lead', 'Contacted', 'Qualified', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'];
            foreach ($defaults as $index => $name) {
                $this->repository->createStage($ctx, $name, $index);
            }
            $stages = $this->repository->getStages($ctx);
        }
        return $stages;
    }

    /**
     * Create deal or update details
     */
    public function saveDeal(TenantContext $ctx, Deal $deal, int $operatorId): array
    {
        $deal->companyId = $ctx->companyId;

        // Check if customer already has a deal (one active deal per customer)
        $existing = $this->repository->findByCustomerId($ctx, $deal->customerId);
        if ($deal->id === null) {
            if ($existing) {
                return ['success' => false, 'error' => 'This customer already has an active CRM sales deal.'];
            }
            $deal->createdBy = $operatorId;
            $newId = $this->repository->create($deal);
            
            // Log creation activity
            $this->repository->logActivity($ctx->companyId, $newId, 'assignment', "Deal created. Assigned to employee.");
            return ['success' => true, 'id' => $newId];
        } else {
            if ($existing && $existing->id !== $deal->id) {
                return ['success' => false, 'error' => 'Another deal is active for this customer.'];
            }
            
            $oldDeal = $this->repository->findById($deal->id, $ctx);
            $success = $this->repository->update($deal);
            if ($success && $oldDeal) {
                // Check if stage updated
                if ($oldDeal->stageId !== $deal->stageId) {
                    $stages = $this->repository->getStages($ctx);
                    $oldName = 'Unknown';
                    $newName = 'Unknown';
                    foreach ($stages as $stg) {
                        if ($stg['id'] === $oldDeal->stageId) $oldName = $stg['name'];
                        if ($stg['id'] === $deal->stageId) $newName = $stg['name'];
                    }
                    $this->repository->logActivity($ctx->companyId, $deal->id, 'stage_change', "Stage changed from '$oldName' to '$newName'.");
                }
                
                // Check if assignment changed
                if ($oldDeal->assignedTo !== $deal->assignedTo) {
                    $this->repository->logActivity($ctx->companyId, $deal->id, 'assignment', "Deal owner reassigned.");
                }
            }
            return ['success' => $success];
        }
    }

    /**
     * Drag & Drop Stage Update
     */
    public function updateDealStage(TenantContext $ctx, int $dealId, int $stageId, ?string $role = null, ?int $userId = null): bool
    {
        $deal = $this->repository->findById($dealId, $ctx, $role, $userId);
        if (!$deal) return false;

        $oldStageId = $deal->stageId;
        $deal->stageId = $stageId;

        $success = $this->repository->update($deal);
        if ($success) {
            $stages = $this->repository->getStages($ctx);
            $oldName = 'Unknown';
            $newName = 'Unknown';
            foreach ($stages as $stg) {
                if ($stg['id'] === $oldStageId) $oldName = $stg['name'];
                if ($stg['id'] === $stageId) $newName = $stg['name'];
            }
            $this->repository->logActivity($ctx->companyId, $dealId, 'stage_change', "Stage changed from '$oldName' to '$newName' via Kanban Board.");
        }

        return $success;
    }

    /**
     * Log note inside deal timeline
     */
    public function addDealNote(TenantContext $ctx, int $dealId, string $notes): void
    {
        $this->repository->logActivity($ctx->companyId, $dealId, 'note', "Note added: \"$notes\"");
    }

    /**
     * Automatic WhatsApp Log hook to record live messages inside the active deal timeline
     */
    public function logWhatsAppActivity(int $companyId, string $mobile, string $direction, string $text): void
    {
        $ctx = TenantContext::forCompany($companyId);
        $customer = $this->customerRepository->findByMobile($ctx, $mobile);
        if (!$customer) return;

        $deal = $this->repository->findByCustomerId($ctx, $customer->id);
        if (!$deal) return;

        $dirLabel = $direction === 'in' ? 'Received WhatsApp message' : 'Sent WhatsApp message';
        $snippet = mb_strimwidth($text, 0, 100, '...');
        $this->repository->logActivity($companyId, $deal->id, 'message', "$dirLabel: \"$snippet\"");
    }
}
