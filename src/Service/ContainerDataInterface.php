<?php

namespace App\Service;

use App\ValueObject\Container;

/**
 * Interface for container data operations
 * 
 * This interface defines the contract for container data services,
 * enabling easy replacement with API-based implementations in the future.
 */
interface ContainerDataInterface
{
    /**
     * Get container data for a specific depot
     * 
     * @param string|null $depotId Optional depot identifier for depot-specific data
     * @return Container[] Array of Container value objects
     */
    public function getContainerData(?string $depotId = null): array;

    /**
     * Get container data formatted for API responses
     * 
     * @param string|null $depotId Optional depot identifier for depot-specific data
     * @return array Array of container data in JSON-serializable format
     */
    public function getFormattedContainerData(?string $depotId = null): array;

    /**
     * Calculate total TEU (Twenty-foot Equivalent Unit) count from containers
     * 
     * @param Container[] $containers Array of Container objects
     * @return int Total TEU count
     */
    public function calculateTotalTEU(array $containers): int;

    /**
     * Get depot name mapping for display purposes
     * 
     * @return array Associative array of depot ID to full name mappings
     */
    public function getDepotNames(): array;

    /**
     * Get full depot name by depot ID
     * 
     * @param string $depotId The depot identifier
     * @return string Full depot name or the depot ID if not found
     */
    public function getDepotFullName(string $depotId): string;

    /**
     * Filter containers by status
     * 
     * @param Container[] $containers Array of Container objects
     * @param string $status Status to filter by
     * @return Container[] Filtered array of Container objects
     */
    public function filterContainersByStatus(array $containers, string $status): array;

    /**
     * Filter containers by condition
     * 
     * @param Container[] $containers Array of Container objects
     * @param string $condition Condition to filter by
     * @return Container[] Filtered array of Container objects
     */
    public function filterContainersByCondition(array $containers, string $condition): array;

    /**
     * Get containers with dwell time exceeding specified days
     * 
     * @param Container[] $containers Array of Container objects
     * @param int $days Minimum dwell time in days
     * @return Container[] Filtered array of Container objects
     */
    public function getContainersWithHighDwellTime(array $containers, int $days): array;

    /**
     * Get detailed container information by container number
     * 
     * @param string $containerNumber The container number to look up
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @return array|null Container details or null if not found
     */
    public function getContainerDetailByNumber(string $containerNumber, ?\App\Entity\ShippingLine $shippingLine = null): ?array;

    /**
     * Get detailed statistics for containers
     * 
     * @param string|null $depotId Optional depot identifier
     * @param \App\Entity\ShippingLine|null $shippingLine Optional shipping line to filter by
     * @param string|null $containerNumber Optional container number to filter by
     * @return array Statistics including counts by size, status, and allocation status
     */
    public function getDetailedStats(?string $depotId = null, ?\App\Entity\ShippingLine $shippingLine = null, ?string $containerNumber = null): array;

    /**
     * Get terminal capacity information
     * 
     * @param string $depotId The depot/terminal identifier
     * @param \App\Entity\StaffUser $user The staff user to get allocation for
     * @return array Capacity information including 20ft and 40ft capacities
     */
    public function getTerminalCapacity(string $depotId, \App\Entity\StaffUser $user): array;
}