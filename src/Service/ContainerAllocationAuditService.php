<?php

namespace App\Service;

use App\Entity\Container;
use App\Entity\ContainerAllocationAudit;
use App\Entity\NOA;
use App\Entity\ShippingLine;
use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class ContainerAllocationAuditService implements ContainerAllocationAuditServiceInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function logAllocationChange(
        Container $container,
        ?ShippingLineTerminalAllocation $previousAllocation,
        ShippingLineTerminalAllocation $newAllocation,
        User $changedBy,
        ?string $reason = null
    ): ContainerAllocationAudit {
        // Determine change type based on context
        $changeType = $this->determineChangeType($container, $previousAllocation);

        // Create audit record
        $audit = new ContainerAllocationAudit();
        $audit->setContainer($container);
        $audit->setPreviousAllocation($previousAllocation);
        $audit->setNewAllocation($newAllocation);
        $audit->setChangedBy($changedBy);
        $audit->setChangeType($changeType);
        $audit->setReason($reason);

        // Add metadata
        $metadata = [
            'container_number' => $container->getContainerNumber(),
            'allocation_status' => $container->getAllocationStatus()->value,
            'previous_terminal' => $previousAllocation ? $previousAllocation->getTerminal()->getName() : null,
            'new_terminal' => $newAllocation->getTerminal()->getName(),
        ];
        $audit->setMetadata($metadata);

        // Persist audit record
        $this->entityManager->persist($audit);
        $this->entityManager->flush();

        return $audit;
    }

    /**
     * {@inheritdoc}
     */
    public function getContainerAuditTrail(Container $container): array
    {
        return $this->entityManager
            ->getRepository(ContainerAllocationAudit::class)
            ->createQueryBuilder('audit')
            ->where('audit.container = :container')
            ->setParameter('container', $container)
            ->orderBy('audit.changedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * {@inheritdoc}
     */
    public function getNOAAuditTrail(NOA $noa): array
    {
        // Get all containers in the NOA
        $containers = $noa->getContainers();

        if ($containers->isEmpty()) {
            return [];
        }

        // Query audit records for all containers in the NOA
        return $this->entityManager
            ->getRepository(ContainerAllocationAudit::class)
            ->createQueryBuilder('audit')
            ->where('audit.container IN (:containers)')
            ->setParameter('containers', $containers->toArray())
            ->orderBy('audit.changedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * {@inheritdoc}
     */
    public function getAuditTrailByDateRange(
        \DateTime $startDate,
        \DateTime $endDate,
        ?ShippingLine $shippingLine = null
    ): array {
        $qb = $this->entityManager
            ->getRepository(ContainerAllocationAudit::class)
            ->createQueryBuilder('audit')
            ->where('audit.changedAt >= :startDate')
            ->andWhere('audit.changedAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        // Filter by shipping line if provided
        if ($shippingLine !== null) {
            $qb->join('audit.container', 'container')
                ->andWhere('container.shippingLine = :shippingLine')
                ->setParameter('shippingLine', $shippingLine);
        }

        return $qb->orderBy('audit.changedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Determine the change type based on context
     */
    private function determineChangeType(
        Container $container,
        ?ShippingLineTerminalAllocation $previousAllocation
    ): string {
        // Initial allocation (no previous allocation)
        if ($previousAllocation === null) {
            return 'initial';
        }

        // Allocation locked (status changed to ALLOCATED)
        if ($container->isAllocationLocked()) {
            return 'locked';
        }

        // Reassignment (changing from one allocation to another)
        return 'reassignment';
    }
}
