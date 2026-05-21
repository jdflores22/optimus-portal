<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\RegenerationRequest;
use App\Entity\User;

/**
 * Interface for eDO regeneration request management
 * 
 * Handles the workflow for requesting regeneration of expired eDOs,
 * routing requests through Terminal Team to Accounting for billing.
 */
interface EDORegenerationServiceInterface
{
    /**
     * Submit a regeneration request for an expired eDO
     * 
     * @param ElectronicDeliveryOrder $edo The expired eDO to regenerate
     * @param User $requester The user submitting the request (Consignee or Broker)
     * @return RegenerationRequest The created regeneration request
     * @throws \InvalidArgumentException If eDO is not expired or requester lacks permission
     */
    public function submitRequest(ElectronicDeliveryOrder $edo, User $requester): RegenerationRequest;

    /**
     * Route a regeneration request to Accounting for billing
     * 
     * @param RegenerationRequest $request The request to route
     * @return void
     */
    public function routeToAccounting(RegenerationRequest $request): void;

    /**
     * Check if an eDO is eligible for regeneration request
     * 
     * @param ElectronicDeliveryOrder $edo The eDO to check
     * @return bool True if regeneration can be requested, false otherwise
     */
    public function canRequestRegeneration(ElectronicDeliveryOrder $edo): bool;
}
