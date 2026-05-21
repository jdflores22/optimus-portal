<?php

namespace App\Service;

use App\Entity\Manifest;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Repository\ManifestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BrokerDeactivationService
{
    public function __construct(
        private UserRepository $userRepo,
        private ManifestRepository $manifestRepo,
        private EntityManagerInterface $em,
        private InAppNotificationService $inAppNotificationService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Deactivate a broker and handle all affected manifests
     */
    public function deactivateBroker(User $broker, User $deactivatedBy, string $reason): void
    {
        // Validate that the user is a broker
        if ($broker->getRole() !== UserRole::BROKER) {
            throw new \InvalidArgumentException('User is not a broker');
        }
        
        // Check if already deactivated
        if (!$broker->getIsActive()) {
            throw new \InvalidArgumentException('Broker is already deactivated');
        }
        
        // Deactivate the broker
        $broker->deactivate($deactivatedBy, $reason);
        
        // Find all active manifests assigned to this broker
        $activeManifests = $this->manifestRepo->findActiveManifestsForBroker($broker->getId());
        
        $this->logger->info('Deactivating broker', [
            'broker_id' => $broker->getId(),
            'broker_email' => $broker->getEmail(),
            'deactivated_by' => $deactivatedBy->getId(),
            'reason' => $reason,
            'affected_manifests' => count($activeManifests)
        ]);
        
        // Mark all active manifests as having inactive broker
        foreach ($activeManifests as $manifest) {
            $manifest->setBrokerInactiveSince(new \DateTime());
            
            // Notify the consignee about the broker deactivation
            $this->notifyConsigneeOfBrokerDeactivation($manifest, $broker);
        }
        
        $this->em->flush();
        
        $this->logger->info('Broker deactivated successfully', [
            'broker_id' => $broker->getId(),
            'affected_manifests' => count($activeManifests)
        ]);
    }

    /**
     * Reactivate a broker
     */
    public function reactivateBroker(User $broker): void
    {
        // Validate that the user is a broker
        if ($broker->getRole() !== UserRole::BROKER) {
            throw new \InvalidArgumentException('User is not a broker');
        }
        
        // Check if already active
        if ($broker->getIsActive()) {
            throw new \InvalidArgumentException('Broker is already active');
        }
        
        $broker->reactivate();
        
        $this->em->flush();
        
        $this->logger->info('Broker reactivated', [
            'broker_id' => $broker->getId(),
            'broker_email' => $broker->getEmail()
        ]);
    }

    /**
     * Get all manifests affected by broker deactivation
     */
    public function getAffectedManifests(User $broker): array
    {
        return $this->manifestRepo->findActiveManifestsForBroker($broker->getId());
    }

    /**
     * Get manifests with inactive broker for a consignee
     */
    public function getManifestsWithInactiveBroker(User $consignee): array
    {
        return $this->manifestRepo->findConsigneeManifestsWithInactiveBroker($consignee->getId());
    }

    /**
     * Notify consignee about broker deactivation
     */
    private function notifyConsigneeOfBrokerDeactivation(Manifest $manifest, User $broker): void
    {
        $consignee = $manifest->getConsignee();
        
        if (!$consignee) {
            $this->logger->warning('Cannot notify consignee - manifest has no consignee', [
                'manifest_id' => $manifest->getId()
            ]);
            return;
        }
        
        try {
            $this->inAppNotificationService->createNotification(
                $consignee,
                'Broker Deactivated',
                sprintf(
                    'Broker %s has been deactivated. Please request a transfer for manifest #%s to continue processing.',
                    $broker->getEmail(),
                    $manifest->getManifestNumber()
                ),
                'warning',
                [
                    'type' => 'broker_deactivated',
                    'manifest_id' => $manifest->getId(),
                    'broker_id' => $broker->getId()
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to send broker deactivation notification', [
                'error' => $e->getMessage(),
                'manifest_id' => $manifest->getId(),
                'consignee_id' => $consignee->getId()
            ]);
        }
    }

    /**
     * Notify shipping line staff about broker deactivation
     */
    public function notifyShippingLineStaff(User $broker, int $affectedManifestCount): void
    {
        try {
            // Get all SL_STAFF users in the same shipping line scope
            $shippingLineScope = $broker->getShippingLineScope();
            
            if (!$shippingLineScope) {
                $this->logger->warning('Cannot notify shipping line staff - broker has no shipping line scope', [
                    'broker_id' => $broker->getId()
                ]);
                return;
            }
            
            $slStaffUsers = $this->userRepo->createQueryBuilder('u')
                ->where('u.role = :role')
                ->andWhere('u.shippingLineAdmin IS NOT NULL')
                ->setParameter('role', UserRole::SL_STAFF)
                ->getQuery()
                ->getResult();
            
            // Filter by shipping line scope
            foreach ($slStaffUsers as $staff) {
                if (method_exists($staff, 'getShippingLineScope')) {
                    $staffScope = $staff->getShippingLineScope();
                    
                    if ($staffScope && $staffScope->getId() === $shippingLineScope->getId()) {
                        $this->inAppNotificationService->createNotification(
                            $staff,
                            'Broker Deactivated',
                            sprintf(
                                'Broker %s has been deactivated. %d manifest(s) are affected and may require transfer approval.',
                                $broker->getEmail(),
                                $affectedManifestCount
                            ),
                            'info',
                            [
                                'type' => 'broker_deactivated_staff',
                                'broker_id' => $broker->getId(),
                                'affected_count' => $affectedManifestCount
                            ]
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to notify shipping line staff', [
                'error' => $e->getMessage(),
                'broker_id' => $broker->getId()
            ]);
        }
    }

    /**
     * Get statistics about broker deactivation impact
     */
    public function getDeactivationImpact(User $broker): array
    {
        $affectedManifests = $this->getAffectedManifests($broker);
        
        $consignees = [];
        foreach ($affectedManifests as $manifest) {
            $consignee = $manifest->getConsignee();
            if ($consignee) {
                $consigneeId = $consignee->getId();
                if (!isset($consignees[$consigneeId])) {
                    $consignees[$consigneeId] = [
                        'consignee' => $consignee,
                        'manifest_count' => 0
                    ];
                }
                $consignees[$consigneeId]['manifest_count']++;
            }
        }
        
        return [
            'total_manifests' => count($affectedManifests),
            'affected_consignees' => count($consignees),
            'consignee_breakdown' => array_values($consignees)
        ];
    }
}
