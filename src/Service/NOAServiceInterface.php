<?php

namespace App\Service;

use App\Entity\NOA;
use App\Entity\NOADocument;
use App\Entity\User;
use App\Entity\Consignee;

interface NOAServiceInterface
{
    /**
     * Generate NOA document for a manifest (legacy)
     */
    public function generateNOA(int $manifestId, array $data, User $slStaff): NOADocument;

    /**
     * Get NOA document by manifest ID (legacy)
     */
    public function getNOAByManifest(int $manifestId): ?NOADocument;

    /**
     * Get NOA document by NOA number (legacy)
     */
    public function getNOAByNumber(string $noaNumber): ?NOADocument;

    /**
     * Create a new NOA with container details (container-based workflow)
     * 
     * @param string $blNumber Bill of Lading number
     * @param string $vesselNumber Vessel identification
     * @param \DateTimeInterface $eta Estimated Time of Arrival
     * @param string $portLocation Port/terminal discharge location
     * @param array $containers Array of container data [['number' => '', 'type' => ContainerType, 'size' => ContainerSize], ...]
     * @param User $creator Terminal team member creating the NOA
     * @return NOA Created NOA entity
     * @throws \InvalidArgumentException If validation fails
     */
    public function createNOA(
        string $blNumber,
        string $vesselNumber,
        \DateTimeInterface $eta,
        string $portLocation,
        Consignee $consignee,
        array $containers,
        User $creator
    ): NOA;

    /**
     * Validate Container Yard capacity for container allocation
     * 
     * @param array $containers Array of containers with size information
     * @param string $cyLocation Container Yard location
     * @return bool True if capacity is sufficient, false otherwise
     */
    public function validateCYCapacity(array $containers, string $cyLocation): bool;

    /**
     * Send notification to consignee about NOA creation
     * 
     * @param NOA $noa The created NOA
     * @return void
     */
    public function notifyConsignee(NOA $noa): void;
}
