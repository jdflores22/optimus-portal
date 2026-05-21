<?php

namespace App\Repository;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Container>
 */
class ContainerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Container::class);
    }

    /**
     * Find container by container number
     */
    public function findByContainerNumber(string $containerNumber): ?Container
    {
        return $this->findOneBy(['containerNumber' => $containerNumber]);
    }

    /**
     * Find containers available for return
     */
    public function findAvailableForReturn(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status = :status')
            ->setParameter('status', ContainerStatus::AVAILABLE_FOR_RETURN)
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if container is available for return by container number
     */
    public function isAvailableForReturn(string $containerNumber): bool
    {
        $container = $this->findByContainerNumber($containerNumber);
        
        return $container !== null && $container->getStatus() === ContainerStatus::AVAILABLE_FOR_RETURN;
    }

    /**
     * Find containers by status
     */
    public function findByStatus(ContainerStatus $status): array
    {
        return $this->findBy(['status' => $status]);
    }

    /**
     * Find containers by type and size
     */
    public function findByTypeAndSize(string $type, string $size): array
    {
        return $this->findBy(['type' => $type, 'size' => $size]);
    }
}