<?php

namespace App\Service;

use App\Entity\Billing;
use App\Entity\Manifest;
use App\Entity\User;

interface BillingServiceInterface
{
    /**
     * Generate billing for a manifest
     */
    public function generateBilling(int $manifestId, array $chargeData, User $slStaff): Billing;

    /**
     * Compute freight charges based on manifest data
     */
    public function computeFreightCharges(Manifest $manifest): float;

    /**
     * Compute THC based on container size and type
     */
    public function computeTHC(string $containerSize, string $containerType): float;

    /**
     * Get billing by manifest ID
     */
    public function getBillingByManifest(int $manifestId): ?Billing;

    /**
     * Get billing by billing ID
     */
    public function getBillingById(int $billingId): ?Billing;

    /**
     * Regenerate billing PDF
     */
    public function regenerateBillingPdf(int $billingId): Billing;
}
