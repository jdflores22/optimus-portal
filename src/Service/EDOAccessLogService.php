<?php

namespace App\Service;

use App\Entity\EDOAccessLog;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class EDOAccessLogService implements EDOAccessLogServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    public function logAccessAttempt(
        ElectronicDeliveryOrder $edo,
        User $user,
        string $ipAddress,
        string $accessResult
    ): void {
        $log = new EDOAccessLog();
        $log->setEdo($edo);
        $log->setAccessedBy($user);
        $log->setAccessedAt(new \DateTime());
        $log->setIpAddress($ipAddress);
        $log->setAccessResult($accessResult);

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
