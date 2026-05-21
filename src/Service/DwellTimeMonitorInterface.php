<?php

namespace App\Service;

interface DwellTimeMonitorInterface
{
    /**
     * Process all containers for dwell time monitoring
     */
    public function processContainers(): void;

    /**
     * Check notification thresholds for all containers
     */
    public function checkNotificationThresholds(): array;

    /**
     * Process automatic returns for containers that have reached the threshold
     */
    public function processAutomaticReturns(): array;

    /**
     * Generate daily report of dwell time monitoring activities
     */
    public function generateDailyReport(): array;
}