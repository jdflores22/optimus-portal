<?php

namespace App\Service;

use App\Entity\ShippingLine;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Service for handling shipping line deactivation notifications
 */
class ShippingLineDeactivationNotificationService
{
    private const DEACTIVATION_FLAG_KEY = 'shipping_line_deactivated_';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private ShippingLineAccessControlService $accessControlService
    ) {
    }

    /**
     * Mark a shipping line as deactivated for all affected users
     */
    public function markShippingLineAsDeactivated(ShippingLine $shippingLine): void
    {
        $affectedUsers = $this->accessControlService->getAffectedUsers($shippingLine);
        
        // Store deactivation flag in cache/session for each affected user
        // This will be checked by the frontend to show the modal
        $flagKey = self::DEACTIVATION_FLAG_KEY . $shippingLine->getId();
        
        // For now, we'll use a simple file-based approach
        // In production, you might want to use Redis or database
        $deactivationData = [
            'shippingLineId' => $shippingLine->getId(),
            'shippingLineName' => $shippingLine->getBrandName(),
            'deactivatedAt' => new \DateTime(),
            'affectedUserIds' => array_map(fn(User $user) => $user->getId(), $affectedUsers)
        ];
        
        $this->storeDeactivationFlag($flagKey, $deactivationData);
    }

    /**
     * Check if a user should see the deactivation modal
     */
    public function shouldShowDeactivationModal(User $user): ?array
    {
        // Get user's shipping line
        $shippingLine = $this->accessControlService->getUserShippingLine($user);
        
        if (!$shippingLine) {
            return null;
        }
        
        $flagKey = self::DEACTIVATION_FLAG_KEY . $shippingLine->getId();
        $deactivationData = $this->getDeactivationFlag($flagKey);
        
        if (!$deactivationData) {
            return null;
        }
        
        // Check if this user is affected and the shipping line is actually deactivated
        if (in_array($user->getId(), $deactivationData['affectedUserIds']) && !$shippingLine->isActive()) {
            return $deactivationData;
        }
        
        return null;
    }

    /**
     * Clear the deactivation flag for a shipping line
     */
    public function clearDeactivationFlag(ShippingLine $shippingLine): void
    {
        $flagKey = self::DEACTIVATION_FLAG_KEY . $shippingLine->getId();
        $this->removeDeactivationFlag($flagKey);
    }

    /**
     * Store deactivation flag (simple file-based implementation)
     */
    private function storeDeactivationFlag(string $key, array $data): void
    {
        $cacheDir = sys_get_temp_dir() . '/optimus_deactivation_flags';
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        $filePath = $cacheDir . '/' . md5($key) . '.json';
        file_put_contents($filePath, json_encode($data));
    }

    /**
     * Get deactivation flag
     */
    private function getDeactivationFlag(string $key): ?array
    {
        $cacheDir = sys_get_temp_dir() . '/optimus_deactivation_flags';
        $filePath = $cacheDir . '/' . md5($key) . '.json';
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);
        
        // Check if flag is older than 1 hour (cleanup old flags)
        if (isset($data['deactivatedAt'])) {
            $deactivatedAt = new \DateTime($data['deactivatedAt']['date']);
            $now = new \DateTime();
            $diff = $now->getTimestamp() - $deactivatedAt->getTimestamp();
            
            if ($diff > 3600) { // 1 hour
                $this->removeDeactivationFlag($key);
                return null;
            }
        }
        
        return $data;
    }

    /**
     * Remove deactivation flag
     */
    private function removeDeactivationFlag(string $key): void
    {
        $cacheDir = sys_get_temp_dir() . '/optimus_deactivation_flags';
        $filePath = $cacheDir . '/' . md5($key) . '.json';
        
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
}