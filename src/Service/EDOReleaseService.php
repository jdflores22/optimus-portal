<?php

namespace App\Service;

use App\Entity\EDOReleaseHistory;
use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Repository\EDOReleaseHistoryRepository;
use App\Repository\ElectronicDeliveryOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class EDOReleaseService implements EDOReleaseServiceInterface
{
    public function __construct(
        private readonly ElectronicDeliveryOrderRepository $edoRepository,
        private readonly EDOReleaseHistoryRepository $historyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly AuditService $auditService
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function getPendingEDOReleases(int $page = 1, int $perPage = 20): array
    {
        $items = $this->edoRepository->getPendingReleases($page, $perPage);
        $total = $this->edoRepository->countPendingReleases();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => (int) ceil($total / $perPage)
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function releaseEDO(int $edoId, User $admin): void
    {
        $edo = $this->edoRepository->findWithRelations($edoId);
        
        if (!$edo) {
            throw new \InvalidArgumentException("eDO with ID {$edoId} not found");
        }

        if ($edo->getStatus() !== EDOStatus::PENDING_RELEASE) {
            throw new \InvalidArgumentException(
                "eDO {$edo->getEdoNumber()} is not in pending_release status (current: {$edo->getStatus()->value})"
            );
        }

        // Verify that the eDO payment is verified before releasing
        $edoPayment = $edo->getEdoPayment();
        if (!$edoPayment) {
            throw new \InvalidArgumentException(
                "eDO {$edo->getEdoNumber()} does not have an associated eDO payment"
            );
        }

        if ($edoPayment->getStatus() !== \App\Entity\Enum\PaymentStatus::VERIFIED) {
            throw new \InvalidArgumentException(
                "eDO {$edo->getEdoNumber()} cannot be released because the eDO payment is not verified (current status: {$edoPayment->getStatus()->value})"
            );
        }

        $this->entityManager->beginTransaction();
        
        try {
            $previousStatus = $edo->getStatus();
            
            // Update eDO status
            $edo->setStatus(EDOStatus::RELEASED);
            $edo->setReleasedBy($admin);
            $edo->setReleasedAt(new \DateTime());
            $edo->setRejectionReason(null);

            // Create history entry
            $this->createHistoryEntry($edo, $previousStatus, EDOStatus::RELEASED, $admin, null);

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Log eDO release with SYSTEM_ADMIN identity and manifest reference
            // Requirement 12.2: Log eDO release with SYSTEM_ADMIN identity, timestamp, and manifest reference
            $this->auditService->logAction(
                $admin,
                'edo_released',
                'ElectronicDeliveryOrder',
                $edo->getId(),
                [
                    'edo_number' => $edo->getEdoNumber(),
                    'manifest_id' => $edo->getManifest()->getId(),
                    'manifest_reference' => $edo->getManifest()->getId(),
                    'edo_payment_id' => $edo->getEdoPayment()?->getId(),
                    'edo_payment_status' => $edo->getEdoPayment()?->getStatus()->value,
                    'from_status' => $previousStatus->value,
                    'to_status' => EDOStatus::RELEASED->value,
                    'released_at' => $edo->getReleasedAt()->format('Y-m-d H:i:s')
                ]
            );

            $this->logger->info('eDO released', [
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber(),
                'released_by' => $admin->getId(),
                'manifest_id' => $edo->getManifest()->getId()
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to release eDO', [
                'edo_id' => $edoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rejectEDO(int $edoId, string $reason, User $admin): void
    {
        if (empty(trim($reason))) {
            throw new \InvalidArgumentException('Rejection reason is required');
        }

        $edo = $this->edoRepository->findWithRelations($edoId);
        
        if (!$edo) {
            throw new \InvalidArgumentException("eDO with ID {$edoId} not found");
        }

        if ($edo->getStatus() !== EDOStatus::PENDING_RELEASE) {
            throw new \InvalidArgumentException(
                "eDO {$edo->getEdoNumber()} is not in pending_release status (current: {$edo->getStatus()->value})"
            );
        }

        $this->entityManager->beginTransaction();
        
        try {
            $previousStatus = $edo->getStatus();
            
            // Update eDO status
            $edo->setStatus(EDOStatus::REJECTED);
            $edo->setRejectionReason($reason);
            $edo->setReleasedBy(null);
            $edo->setReleasedAt(null);

            // Create history entry
            $this->createHistoryEntry($edo, $previousStatus, EDOStatus::REJECTED, $admin, $reason);

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Log eDO rejection with SYSTEM_ADMIN identity, rejection reason, and manifest reference
            // Requirement 12.3: Log eDO rejection with SYSTEM_ADMIN identity, timestamp, rejection reason, and manifest reference
            $this->auditService->logAction(
                $admin,
                'edo_rejected',
                'ElectronicDeliveryOrder',
                $edo->getId(),
                [
                    'edo_number' => $edo->getEdoNumber(),
                    'manifest_id' => $edo->getManifest()->getId(),
                    'manifest_reference' => $edo->getManifest()->getId(),
                    'edo_payment_id' => $edo->getEdoPayment()?->getId(),
                    'edo_payment_status' => $edo->getEdoPayment()?->getStatus()->value,
                    'from_status' => $previousStatus->value,
                    'to_status' => EDOStatus::REJECTED->value,
                    'rejection_reason' => $reason,
                    'rejected_at' => date('Y-m-d H:i:s')
                ]
            );

            $this->logger->info('eDO rejected', [
                'edo_id' => $edo->getId(),
                'edo_number' => $edo->getEdoNumber(),
                'rejected_by' => $admin->getId(),
                'reason' => $reason,
                'manifest_id' => $edo->getManifest()->getId()
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Failed to reject eDO', [
                'edo_id' => $edoId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getEDOReleaseHistory(int $edoId): array
    {
        return $this->historyRepository->getHistoryByEDOId($edoId);
    }

    /**
     * {@inheritdoc}
     */
    public function canAccessEDO(ElectronicDeliveryOrder $edo, User $user): bool
    {
        // SYSTEM_ADMIN can always access eDOs
        if ($user->getRole() === UserRole::SYSTEM_ADMIN) {
            return true;
        }

        // Only released eDOs can be accessed by non-admin users
        if ($edo->getStatus() !== EDOStatus::RELEASED) {
            return false;
        }

        $manifest = $edo->getManifest();
        
        // Check if user is the broker
        if ($manifest->getBroker() && $manifest->getBroker()->getId() === $user->getId()) {
            return true;
        }

        // Check if user is the consignee
        if ($manifest->getConsignee() && $manifest->getConsignee()->getId() === $user->getId()) {
            return true;
        }

        return false;
    }

    /**
     * Create a history entry for eDO status change
     */
    private function createHistoryEntry(
        ElectronicDeliveryOrder $edo,
        EDOStatus $fromStatus,
        EDOStatus $toStatus,
        User $actor,
        ?string $rejectionReason
    ): void {
        $history = new EDOReleaseHistory();
        $history->setEdo($edo);
        $history->setFromStatus($fromStatus);
        $history->setToStatus($toStatus);
        $history->setActor($actor);
        $history->setRejectionReason($rejectionReason);

        $this->entityManager->persist($history);
        $edo->addReleaseHistory($history);
    }
}
