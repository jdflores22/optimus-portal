<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\User;
use App\Exception\EDOGenerationException;

/**
 * Interface for batch Electronic Delivery Order (eDO) generation operations.
 * 
 * This service handles the generation of eDOs for all containers in a manifest
 * after the broker's billing payment has been verified by the Accounting department.
 */
interface BatchEDOGenerationServiceInterface
{
    /**
     * Generate eDOs for all containers linked to a manifest.
     * 
     * Creates one Electronic Delivery Order (eDO) for each container that is linked
     * to the specified manifest. Each eDO will have a unique number, be linked to
     * its specific container, and have the specified expiration date.
     * 
     * The operation is performed within a database transaction to ensure atomicity.
     * If any part of the generation fails, all changes are rolled back.
     * 
     * @param Manifest $manifest The manifest for which to generate eDOs
     * @param \DateTimeInterface $expirationDate The expiration date for all generated eDOs
     * @param User $generatedBy The SL_STAFF user generating the eDOs
     * 
     * @return array An array containing:
     *               - 'count': int - Number of eDOs generated
     *               - 'edos': ElectronicDeliveryOrder[] - Array of generated eDO entities
     * 
     * @throws EDOGenerationException When manifest validation fails
     * @throws EDOGenerationException When no containers are linked to the manifest
     * @throws EDOGenerationException When eDOs already exist for the manifest
     * @throws EDOGenerationException When database transaction fails
     */
    public function generateEDOsForManifest(
        Manifest $manifest,
        \DateTimeInterface $expirationDate,
        User $generatedBy
    ): array;

    /**
     * Validate that a manifest is ready for eDO generation.
     * 
     * Performs comprehensive validation to ensure the manifest meets all
     * requirements for eDO generation:
     * - Workflow state must be 'payment_verified'
     * - Must have a final payment with 'verified' status
     * - Must not already have eDOs generated
     * - Must have at least one container linked
     * 
     * @param Manifest $manifest The manifest to validate
     * 
     * @return bool True if validation passes
     * 
     * @throws EDOGenerationException When workflow state is not 'payment_verified'
     * @throws EDOGenerationException When no final payment exists
     * @throws EDOGenerationException When final payment is not verified
     * @throws EDOGenerationException When eDOs already exist for the manifest
     * @throws EDOGenerationException When no containers are linked to the manifest
     */
    public function validateManifestForEDOGeneration(Manifest $manifest): bool;
}