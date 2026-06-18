<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\WorkflowState;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ManifestAuthorizationService implements ManifestAuthorizationServiceInterface
{
    public function __construct(
        private CacheInterface $manifestAuthorizationCache
    ) {
    }

    public function canViewManifest(Manifest $manifest, User $user): bool
    {
        $cacheKey = sprintf('manifest_auth.view.%d.%d', $manifest->getId(), $user->getId());
        
        return $this->manifestAuthorizationCache->get($cacheKey, function (ItemInterface $item) use ($manifest, $user) {
            $item->expiresAfter(1800); // 30 minutes
            
            $role = $user->getRole()->value;

            // SL_STAFF, shipping lines hierarchy, SYSTEM_ADMIN, and ACCOUNTING can view
            if (in_array($role, ['SL_STAFF', 'SHIPPING_LINES_ADMIN', 'EVALUATOR', 'TERMINAL_TEAM', 'SYSTEM_ADMIN', 'ACCOUNTING'])) {
                return true;
            }

            // Broker can view if associated with manifest (immediate access after consignee declaration)
            if ($role === 'BROKER' && $manifest->getBroker()?->getId() === $user->getId()) {
                // Additional check: verify broker is actually assigned to this specific manifest
                if ($manifest->getBroker() === null) {
                    return false;
                }
                return true;
            }

            // Consignee can view if associated with manifest (immediate access after consignee declaration)
            if ($role === 'CONSIGNEE' && $manifest->getConsignee()?->getId() === $user->getId()) {
                return true;
            }

            return false;
        });
    }

    public function canUploadBL(Manifest $manifest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only Broker can upload BL
        if ($role !== 'BROKER') {
            return false;
        }

        // Must be associated with the manifest
        if ($manifest->getBroker()?->getId() !== $user->getId()) {
            return false;
        }

        // Manifest must be in noa_generated or bl_generated state
        return in_array($manifest->getWorkflowState(), [WorkflowState::NOA_GENERATED, WorkflowState::BL_GENERATED]);
    }

    public function canSubmitFinalPayment(Manifest $manifest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only Broker can submit final payment
        if ($role !== 'BROKER') {
            return false;
        }

        // Must be associated with the manifest
        if ($manifest->getBroker()?->getId() !== $user->getId()) {
            return false;
        }

        // Manifest must be in billing_generated state
        return $manifest->getWorkflowState() === WorkflowState::BILLING_GENERATED;
    }

    public function canGenerateNOA(Manifest $manifest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only SL_STAFF can generate NOA
        if ($role !== 'SL_STAFF') {
            return false;
        }

        // Manifest must have consignee declared (no longer requires payment_verified state)
        return $manifest->getConsignee() !== null;
    }

    public function canGenerateBilling(Manifest $manifest, User $user): bool
    {
        $role = $user->getRole()->value;

        // Only ACCOUNTING can generate billing
        // This is a financial operation that should be handled by accounting department
        if ($role !== 'ACCOUNTING') {
            return false;
        }

        // Manifest must be in bl_uploaded state
        return $manifest->getWorkflowState() === WorkflowState::BL_UPLOADED;
    }

    /**
     * Invalidate cache for a specific manifest and user
     */
    public function invalidateCache(Manifest $manifest, User $user): void
    {
        $cacheKey = sprintf('manifest_auth.view.%d.%d', $manifest->getId(), $user->getId());
        $this->manifestAuthorizationCache->delete($cacheKey);
    }

    /**
     * Invalidate cache for all users on a manifest (e.g., after payment verification)
     */
    public function invalidateManifestCache(Manifest $manifest): void
    {
        // Clear cache for broker
        if ($manifest->getBroker()) {
            $this->invalidateCache($manifest, $manifest->getBroker());
        }
        
        // Clear cache for consignee
        if ($manifest->getConsignee()) {
            $this->invalidateCache($manifest, $manifest->getConsignee());
        }
    }
}
