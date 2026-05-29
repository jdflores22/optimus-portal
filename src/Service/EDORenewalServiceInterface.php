<?php

namespace App\Service;

use App\Entity\EDORenewalRequest;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use App\Entity\ShippingLineTerminalAllocation;

interface EDORenewalServiceInterface
{
    /**
     * Create a new eDO renewal request
     * 
     * @param ElectronicDeliveryOrder $expiredEdo The expired eDO
     * @param User $requestedBy The broker requesting renewal
     * @param \DateTimeInterface $returnDate Requested empty container return date
     * @param string|null $notes Additional notes from broker
     * @return EDORenewalRequest The created renewal request
     * @throws \InvalidArgumentException If eDO is not expired or return date is invalid
     */
    public function createRenewalRequest(
        ElectronicDeliveryOrder $expiredEdo,
        User $requestedBy,
        \DateTimeInterface $returnDate,
        ?string $notes = null
    ): EDORenewalRequest;

    /**
     * Validate that the requested return date is within office hours and not in the past
     * 
     * @param \DateTimeInterface $returnDate The requested return date
     * @return bool True if valid, false otherwise
     */
    public function validateRequestDate(\DateTimeInterface $returnDate): bool;

    /**
     * Mark payment as verified for a renewal request
     * 
     * @param EDORenewalRequest $request The renewal request
     * @param User $verifiedBy The user verifying payment
     * @return void
     */
    public function markPaymentVerified(EDORenewalRequest $request, User $verifiedBy): void;

    /**
     * Generate a new eDO for a renewal request
     * 
     * @param EDORenewalRequest $request The renewal request
     * @param User $generatedBy The SL staff generating the eDO
     * @param ShippingLineTerminalAllocation $cyAllocation The container yard allocation
     * @param string|null $additionalNotes Additional notes for the new eDO
     * @return ElectronicDeliveryOrder The newly generated eDO
     * @throws \RuntimeException If payment is not verified when required
     */
    public function generateNewEDO(
        EDORenewalRequest $request,
        User $generatedBy,
        ShippingLineTerminalAllocation $cyAllocation,
        ?string $additionalNotes = null
    ): ElectronicDeliveryOrder;

    /**
     * Check if an eDO is eligible for renewal
     * 
     * @param ElectronicDeliveryOrder $edo The eDO to check
     * @return bool True if eligible, false otherwise
     */
    public function isEligibleForRenewal(ElectronicDeliveryOrder $edo): bool;

    /**
     * Get pending renewal requests for SL staff
     * 
     * @param User $slStaff The SL staff user
     * @return array<EDORenewalRequest> Array of pending requests
     */
    public function getPendingRenewalRequests(User $slStaff): array;
}
