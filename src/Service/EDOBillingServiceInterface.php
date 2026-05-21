<?php

namespace App\Service;

use App\Entity\EDOBilling;
use App\Entity\RegenerationRequest;
use App\Entity\User;

/**
 * Interface for eDO billing service
 * 
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5
 */
interface EDOBillingServiceInterface
{
    /**
     * Calculate billing for expired eDO days
     * 
     * @param RegenerationRequest $regenerationRequest
     * @param User $accountingUser
     * @return EDOBilling
     */
    public function calculateBilling(RegenerationRequest $regenerationRequest, User $accountingUser): EDOBilling;

    /**
     * Generate billing document as PDF
     * 
     * @param EDOBilling $billing
     * @return string Path to generated PDF
     */
    public function generateBillingDocument(EDOBilling $billing): string;

    /**
     * Send billing notifications to Consignee and Broker
     * 
     * @param EDOBilling $billing
     * @return void
     */
    public function sendBillingToParties(EDOBilling $billing): void;
}
