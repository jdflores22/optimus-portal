<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\User;

interface ManifestAuthorizationServiceInterface
{
    /**
     * Check if user can view manifest
     */
    public function canViewManifest(Manifest $manifest, User $user): bool;

    /**
     * Check if user can upload BL
     */
    public function canUploadBL(Manifest $manifest, User $user): bool;

    /**
     * Check if user can submit final payment
     */
    public function canSubmitFinalPayment(Manifest $manifest, User $user): bool;

    /**
     * Check if user can generate NOA
     */
    public function canGenerateNOA(Manifest $manifest, User $user): bool;

    /**
     * Check if user can generate billing
     */
    public function canGenerateBilling(Manifest $manifest, User $user): bool;
}
