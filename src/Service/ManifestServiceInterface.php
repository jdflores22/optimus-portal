<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\WorkflowState;

interface ManifestServiceInterface
{
    /**
     * Upload a new manifest
     */
    public function uploadManifest(array $data, User $slStaff): Manifest;

    /**
     * Create a manifest with NOA validation and eDO generation
     * 
     * @param array $data Manifest data including NOA ID, BL number, and BL file
     * @param User $broker The broker creating the manifest
     * @return Manifest The created manifest with generated eDOs
     * @throws \InvalidArgumentException If validation fails
     */
    public function createManifestWithEDO(array $data, User $broker): Manifest;

    /**
     * Declare a consignee for a manifest
     */
    public function declareConsignee(int $manifestId, int $consigneeId, User $slStaff): void;

    /**
     * Get manifest by ID
     */
    public function getManifestById(int $id): ?Manifest;

    /**
     * Get manifest by BL number
     */
    public function getManifestByBlNumber(string $blNumber): ?Manifest;

    /**
     * Check if a user can view a manifest
     */
    public function canViewManifest(Manifest $manifest, User $user): bool;

    /**
     * Transition manifest to a new workflow state
     */
    public function transitionState(Manifest $manifest, WorkflowState $newState, User $actor): void;
}
