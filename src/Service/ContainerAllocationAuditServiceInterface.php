<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\ContainerAllocationAudit;
use App\Entity\NOA;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\User;

interface ContainerAllocationAuditServiceInterface
{
    /**
     * Log allocation change for audit trail
     * 
     * @param Container $container The container being allocated
     * @param ShippingLineTerminalAllocation|null $previousAllocation Previous allocation (null for initial)
     * @param ShippingLineTerminalAllocation $newAllocation New allocation
     * @param User $changedBy User making the change
     * @param string|null $reason Optional reason for the change
     * @return ContainerAllocationAudit The created audit record
     */
    public function logAllocationChange(
        Container $container,
        ?ShippingLineTerminalAllocation $previousAllocation,
        ShippingLineTerminalAllocation $newAllocation,
        User $changedBy,
        ?string $reason = null
    ): ContainerAllocationAudit;

    /**
     * Get audit trail for a container
     * Returns all allocation changes in chronological order
     * 
     * @param Container $container
     * @return ContainerAllocationAudit[]
     */
    public function getContainerAuditTrail(Container $container): array;

    /**
     * Get audit trail for all containers in a NOA
     * Returns combined audit records for all containers
     * 
     * @param NOA $noa
     * @return ContainerAllocationAudit[]
     */
    public function getNOAAuditTrail(NOA $noa): array;

    /**
     * Get allocation changes within date range
     * Optionally filter by shipping line
     * 
     * @param \DateTime $startDate
     * @param \DateTime $endDate
     * @param ShippingLine|null $shippingLine Optional shipping line filter
     * @return ContainerAllocationAudit[]
     */
    public function getAuditTrailByDateRange(
        \DateTime $startDate,
        \DateTime $endDate,
        ?ShippingLine $shippingLine = null
    ): array;
}
