<?php

namespace Core;

/**
 * TenantContext — immutable value object representing the current tenant scope.
 *
 * Today it wraps company_id from the session.
 * Future: swap the factory method to resolve workspace_id without touching any service.
 *
 * Rules:
 *  - Services accept TenantContext, never raw company_id.
 *  - Do NOT add mutable state here.
 *  - Do NOT rename $companyId — it maps to the live DB column.
 */
final class TenantContext
{
    private function __construct(
        public readonly int $companyId
    ) {}

    /**
     * Build from the active PHP session.
     * Throws if the session has no company_id (caller must guard auth first).
     */
    public static function fromSession(): self
    {
        if (!isset($_SESSION['company_id'])) {
            throw new \RuntimeException('TenantContext: no company_id in session');
        }
        return new self((int) $_SESSION['company_id']);
    }

    /**
     * Build directly from a known company ID (webhook, CLI, tests).
     */
    public static function forCompany(int $companyId): self
    {
        return new self($companyId);
    }
}
