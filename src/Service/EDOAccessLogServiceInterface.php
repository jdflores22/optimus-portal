<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;

interface EDOAccessLogServiceInterface
{
    /**
     * Log an eDO access attempt
     */
    public function logAccessAttempt(
        ElectronicDeliveryOrder $edo,
        User $user,
        string $ipAddress,
        string $accessResult
    ): void;
}
