<?php

namespace App\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\RegenerationRequest;
use App\Entity\User;
use App\Entity\Enum\EDOStatus;
use App\Entity\Enum\RequestStatus;
use App\Repository\RegenerationRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing eDO regeneration requests
 * 
 * Handles the workflow for requesting regeneration of expired eDOs,
 * routing requests through Terminal Team to Accounting for billing.
 */
class EDORegenerationService implements EDORegenerationServiceInterface
{
    public function __construct(
        private RegenerationRequestRepository $regenerationRequestRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function submitRequest(ElectronicDeliveryOrder $edo, User $requester): RegenerationRequest
    {
        // Validate eDO is eligible for regeneration
        if (!$this->canRequestRegeneration($edo)) {
            throw new \InvalidArgumentException(
                sprintf('eDO %s is not eligible for regeneration. Only expired eDOs can be regenerated.', $edo->getEdoNumber())
            );
        }

        // Check if there's already a pending request for this eDO
        $existingRequests = $this->regenerationRequestRepository->findByEDO($edo);
        foreach ($existingRequests as $existingRequest) {
            if (in_array($existingRequest->getStatus(), [
                RequestStatus::SUBMITTED,
                RequestStatus::ROUTED_TO_ACCOUNTING,
                RequestStatus::BILLING_GENERATED,
                RequestStatus::PAYMENT_SUBMITTED
            ])) {
                throw new \InvalidArgumentException(
                    sprintf('A regeneration request for eDO %s is already in progress.', $edo->getEdoNumber())
                );
            }
        }

        // Create regeneration request
        $request = new RegenerationRequest();
        $request->setEdo($edo);
        $request->setRequester($requester);
        $request->setStatus(RequestStatus::SUBMITTED);
        $request->setRequestedAt(new \DateTime());

        $this->regenerationRequestRepository->save($request);

        // TODO: Re-implement audit logging with general AuditService
        // Log regeneration request

        $this->logger->info('eDO regeneration request submitted', [
            'edo_number' => $edo->getEdoNumber(),
            'requester_id' => $requester->getId(),
            'request_id' => $request->getId()
        ]);

        return $request;
    }

    /**
     * {@inheritdoc}
     */
    public function routeToAccounting(RegenerationRequest $request): void
    {
        if ($request->getStatus() !== RequestStatus::SUBMITTED) {
            throw new \InvalidArgumentException(
                sprintf('Only submitted requests can be routed to accounting. Current status: %s', $request->getStatus()->value)
            );
        }

        $request->setStatus(RequestStatus::ROUTED_TO_ACCOUNTING);
        $request->setRoutedToAccountingAt(new \DateTime());

        $this->entityManager->flush();

        $this->logger->info('Regeneration request routed to accounting', [
            'request_id' => $request->getId(),
            'edo_number' => $request->getEdo()->getEdoNumber()
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function canRequestRegeneration(ElectronicDeliveryOrder $edo): bool
    {
        // Only expired eDOs can be regenerated
        return $edo->getStatus() === EDOStatus::EXPIRED;
    }
}
