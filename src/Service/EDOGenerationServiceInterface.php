<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Manifest;

/**
 * Interface for eDO generation service
 * Handles container-level eDO creation and management
 */
interface EDOGenerationServiceInterface
{
    /**
     * Generate eDOs for all containers in a manifest
     * Creates one eDO per container
     *
     * @param Manifest $manifest The manifest containing containers
     * @return array<ElectronicDeliveryOrder> Array of generated eDOs
     */
    public function generateEDOsForManifest(Manifest $manifest): array;

    /**
     * Generate a single eDO for a specific container
     *
     * @param Container $container The container to generate eDO for
     * @param Manifest $manifest The associated manifest
     * @return ElectronicDeliveryOrder The generated eDO
     */
    public function generateEDOForContainer(Container $container, Manifest $manifest): ElectronicDeliveryOrder;

    /**
     * Assign a unique eDO number
     * Format: EDO-YYYYMMDD-CONTAINER-XXXX
     *
     * @param string $containerNumber The container number
     * @return string The generated eDO number
     */
    public function assignEDONumber(string $containerNumber): string;
}
