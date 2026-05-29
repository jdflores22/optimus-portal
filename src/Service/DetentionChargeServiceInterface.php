<?php

namespace App\Service;

use App\Entity\EDORenewalRequest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Billing;

/**
 * Service interface for calculating and managing detention charges for expired eDOs
 * 
 * Detention charges are applied when containers are held beyond the allowed free time period.
 * This service handles the calculation of overdue days, detention charge amounts, and billing generation.
 */
interface DetentionChargeServiceInterface
{
    /**
     * Calculate overdue days for an expired eDO
     * 
     * Overdue days are calculated as the difference between the current date and the eDO expiration date.
     * If the eDO has not yet expired, this method returns 0.
     * 
     * @param ElectronicDeliveryOrder $edo The expired eDO
     * @return int Number of overdue days (0 if not overdue)
     */
    public function calculateOverdueDays(ElectronicDeliveryOrder $edo): int;

    /**
     * Calculate detention charge amount based on overdue days
     * 
     * The detention charge is calculated using a rate schedule that may vary based on:
     * - Container size (20ft, 40ft, etc.)
     * - Container type (standard, reefer, etc.)
     * - Number of overdue days
     * - Shipping line specific rates
     * 
     * @param int $overdueDays Number of overdue days
     * @param ElectronicDeliveryOrder $edo The eDO (for container size/type and shipping line context)
     * @return float Detention charge amount in the configured currency
     */
    public function calculateDetentionCharge(int $overdueDays, ElectronicDeliveryOrder $edo): float;

    /**
     * Generate billing records for detention charges
     * 
     * Creates billing records for both the consignee and broker when detention charges apply.
     * The billing records are linked to the renewal request and include:
     * - Detention days
     * - Detention rate applied
     * - Total detention charge amount
     * - Billing type set to 'detention'
     * 
     * This method also logs the billing generation via AuditLogService.
     * 
     * @param EDORenewalRequest $request The renewal request requiring detention charges
     * @return Billing The generated billing record
     * @throws \RuntimeException If billing generation fails
     */
    public function generateDetentionBilling(EDORenewalRequest $request): Billing;

    /**
     * Check if detention charges are required for a renewal request
     * 
     * Detention charges are required when the number of overdue days is greater than zero.
     * This is a convenience method to determine if payment verification is needed before eDO generation.
     * 
     * @param EDORenewalRequest $request The renewal request to check
     * @return bool True if charges required (overdue days > 0), false otherwise
     */
    public function requiresDetentionCharges(EDORenewalRequest $request): bool;
}
