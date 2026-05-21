<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\User;

interface DwellTimeServiceInterface
{
    /**
     * Calculate current dwell time for a container
     */
    public function calculateCurrentDwellTime(Container $container): int;
    
    /**
     * Pause dwell time counting for a container
     */
    public function pauseDwellTime(Container $container, string $reason, ?User $triggeredBy = null): void;
    
    /**
     * Resume dwell time counting for a container
     */
    public function resumeDwellTime(Container $container, ?User $triggeredBy = null): void;
    
    /**
     * Check notification thresholds for a container
     */
    public function checkNotificationThresholds(Container $container): array;
    
    /**
     * Process automatic return for a container
     */
    public function processAutomaticReturn(Container $container): void;
    
    /**
     * Get dwell time history for a container
     */
    public function getDwellTimeHistory(Container $container): array;
    
    /**
     * Update container dwell time fields
     */
    public function updateContainerDwellTime(Container $container): void;
    
    /**
     * Handle container status change for dwell time management
     */
    public function handleStatusChange(Container $container, ContainerStatus $oldStatus, ContainerStatus $newStatus, ?User $triggeredBy = null): void;
}