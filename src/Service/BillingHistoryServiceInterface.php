<?php

namespace App\Service;

use App\Entity\Billing;
use App\Entity\EDORenewalRequest;

/**
 * Interface for Billing History Service
 * Provides methods to retrieve billing history, billing chains, and statistics
 */
interface BillingHistoryServiceInterface
{
    /**
     * Get complete billing history for a renewal request
     * Returns all billing versions ordered by version number (ascending)
     * 
     * @param EDORenewalRequest $renewalRequest The renewal request to get billing history for
     * @return array Array of Billing entities ordered by version
     */
    public function getBillingHistory(EDORenewalRequest $renewalRequest): array;

    /**
     * Get billing chain starting from a specific billing
     * Walks backwards to find the root (v1) then builds forward chain
     * 
     * @param Billing $billing The billing to get the chain for
     * @return array Array of Billing entities from v1 to latest version
     */
    public function getBillingChain(Billing $billing): array;

    /**
     * Get billing statistics for a renewal request
     * Includes total versions, current version, and submission dates
     * 
     * @param EDORenewalRequest $renewalRequest The renewal request to get statistics for
     * @return array Statistics array with keys: total_versions, current_version, 
     *               first_submission, last_submission
     */
    public function getBillingStatistics(EDORenewalRequest $renewalRequest): array;

    /**
     * Invalidate billing history cache for a renewal request
     * Should be called when a new billing is submitted or updated
     * 
     * @param EDORenewalRequest $renewalRequest The renewal request to invalidate cache for
     */
    public function invalidateBillingHistoryCache(EDORenewalRequest $renewalRequest): void;

    /**
     * Invalidate billing chain cache for a specific billing
     * Should be called when a billing is updated or a new version is created
     * 
     * @param Billing $billing The billing to invalidate cache for
     */
    public function invalidateBillingChainCache(Billing $billing): void;
}
