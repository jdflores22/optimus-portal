<?php

namespace App\Service;

use App\Entity\BrokerTransferRequest;
use App\Entity\Manifest;
use App\Entity\User;
use App\Repository\BrokerTransferRequestRepository;
use App\Repository\ManifestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class BrokerTransferService
{
    public function __construct(
        private BrokerTransferRequestRepository $transferRequestRepo,
        private ManifestRepository $manifestRepo,
        private EntityManagerInterface $em,
        private InAppNotificationService $inAppNotificationService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Create a new broker transfer request
     */
    public function createTransferRequest(
        Manifest $manifest,
        User $consignee,
        User $newBroker,
        string $reason
    ): BrokerTransferRequest {
        // Validate ownership
        if ($manifest->getConsignee() !== $consignee) {
            throw new \InvalidArgumentException('Manifest does not belong to this consignee');
        }
        
        // Check if there's already a pending request for this manifest
        $existingRequest = $this->transferRequestRepo->findPendingForManifest($manifest);
        if ($existingRequest) {
            throw new \InvalidArgumentException('There is already a pending transfer request for this manifest');
        }
        
        // Validate that manifest has a broker
        if (!$manifest->getBroker()) {
            throw new \InvalidArgumentException('Manifest does not have a broker assigned');
        }
        
        $transferRequest = new BrokerTransferRequest();
        $transferRequest->setManifest($manifest);
        $transferRequest->setConsignee($consignee);
        $transferRequest->setOldBroker($manifest->getBroker());
        $transferRequest->setNewBroker($newBroker);
        $transferRequest->setReason($reason);
        $transferRequest->setStatus(BrokerTransferRequest::STATUS_PENDING);
        $transferRequest->setRequestedBy($consignee);
        
        $this->em->persist($transferRequest);
        $this->em->flush();
        
        $this->logger->info('Broker transfer request created', [
            'manifest_id' => $manifest->getId(),
            'consignee_id' => $consignee->getId(),
            'old_broker_id' => $manifest->getBroker()->getId(),
            'new_broker_id' => $newBroker->getId()
        ]);
        
        // Send notification to SYSTEM_ADMIN users
        try {
            // Get all SYSTEM_ADMIN users
            $systemAdmins = $this->em->getRepository(User::class)
                ->createQueryBuilder('u')
                ->where('u.role = :role')
                ->setParameter('role', \App\Entity\Enum\UserRole::SYSTEM_ADMIN)
                ->getQuery()
                ->getResult();
            
            foreach ($systemAdmins as $admin) {
                $this->inAppNotificationService->createNotification(
                    $admin,
                    'New Broker Transfer Request',
                    sprintf(
                        'New broker transfer request from %s for manifest #%s. Please review the request.',
                        $consignee->getEmail(),
                        $manifest->getManifestNumber()
                    ),
                    'broker_transfer_request',
                    ['transfer_request_id' => $transferRequest->getId(), 'manifest_id' => $manifest->getId()]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to send transfer request notification', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $transferRequest;
    }

    /**
     * Approve a transfer request
     */
    public function approveTransfer(BrokerTransferRequest $transferRequest, User $reviewer): void
    {
        if (!$transferRequest->isPending()) {
            throw new \InvalidArgumentException('Transfer request is not pending');
        }
        
        $transferRequest->approve($reviewer);
        
        $manifest = $transferRequest->getManifest();
        $manifest->transferBroker($transferRequest->getNewBroker());
        
        // Clear broker inactive status if set
        if ($manifest->getBrokerInactiveSince()) {
            $manifest->setBrokerInactiveSince(null);
        }
        
        $this->em->flush();
        
        $this->logger->info('Broker transfer request approved', [
            'transfer_request_id' => $transferRequest->getId(),
            'manifest_id' => $manifest->getId(),
            'reviewer_id' => $reviewer->getId(),
            'new_broker_id' => $transferRequest->getNewBroker()->getId()
        ]);
    }

    /**
     * Reject a transfer request
     */
    public function rejectTransfer(BrokerTransferRequest $transferRequest, User $reviewer, string $notes): void
    {
        if (!$transferRequest->isPending()) {
            throw new \InvalidArgumentException('Transfer request is not pending');
        }
        
        $transferRequest->reject($reviewer, $notes);
        
        $this->em->flush();
        
        $this->logger->info('Broker transfer request rejected', [
            'transfer_request_id' => $transferRequest->getId(),
            'manifest_id' => $transferRequest->getManifest()->getId(),
            'reviewer_id' => $reviewer->getId(),
            'notes' => $notes
        ]);
    }

    /**
     * Get all pending transfer requests
     */
    public function getPendingTransferRequests(): array
    {
        return $this->transferRequestRepo->findPending();
    }

    /**
     * Get all approved transfer requests
     */
    public function getApprovedTransferRequests(): array
    {
        return $this->transferRequestRepo->findByStatus(BrokerTransferRequest::STATUS_APPROVED);
    }

    /**
     * Get all rejected transfer requests
     */
    public function getRejectedTransferRequests(): array
    {
        return $this->transferRequestRepo->findByStatus(BrokerTransferRequest::STATUS_REJECTED);
    }

    /**
     * Get transfer requests for a consignee
     */
    public function getTransferRequestsForConsignee(User $consignee, ?string $status = null): array
    {
        return $this->transferRequestRepo->findByConsignee($consignee, $status);
    }

    /**
     * Get transfer requests for a manifest
     */
    public function getTransferRequestsForManifest(Manifest $manifest): array
    {
        return $this->transferRequestRepo->findByManifest($manifest);
    }

    /**
     * Get pending request for a manifest
     */
    public function getPendingRequestForManifest(Manifest $manifest): ?BrokerTransferRequest
    {
        return $this->transferRequestRepo->findPendingForManifest($manifest);
    }

    /**
     * Get transfer requests involving a broker
     */
    public function getTransferRequestsForBroker(User $broker): array
    {
        return $this->transferRequestRepo->findByBroker($broker);
    }

    /**
     * Count pending transfer requests
     */
    public function countPendingRequests(): int
    {
        return $this->transferRequestRepo->countPending();
    }

    /**
     * Get recently reviewed transfer requests
     */
    public function getRecentlyReviewedRequests(int $limit = 10): array
    {
        return $this->transferRequestRepo->findRecentlyReviewed($limit);
    }

    /**
     * Get transfer requests reviewed by a specific user
     */
    public function getRequestsReviewedBy(User $reviewer): array
    {
        return $this->transferRequestRepo->findReviewedBy($reviewer);
    }

    /**
     * Cancel a pending transfer request (by consignee)
     */
    public function cancelTransferRequest(BrokerTransferRequest $transferRequest, User $consignee): void
    {
        if ($transferRequest->getConsignee() !== $consignee) {
            throw new \InvalidArgumentException('Transfer request does not belong to this consignee');
        }
        
        if (!$transferRequest->isPending()) {
            throw new \InvalidArgumentException('Can only cancel pending transfer requests');
        }
        
        // We'll reject it with a special note
        $transferRequest->reject($consignee, 'Cancelled by consignee');
        
        $this->em->flush();
        
        $this->logger->info('Broker transfer request cancelled', [
            'transfer_request_id' => $transferRequest->getId(),
            'manifest_id' => $transferRequest->getManifest()->getId(),
            'consignee_id' => $consignee->getId()
        ]);
    }
}
