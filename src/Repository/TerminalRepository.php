<?php

namespace App\Repository;

use App\Entity\Terminal;
use App\Entity\Enum\TerminalType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Terminal>
 */
class TerminalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Terminal::class);
    }

    /**
     * Find all active port/terminal locations (excludes container yards).
     *
     * @return Terminal[]
     */
    public function findActivePorts(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isActive = :active')
            ->andWhere('t.type != :cy')
            ->setParameter('active', true)
            ->setParameter('cy', TerminalType::CY)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active terminals
     */
    public function findActive(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    /**
     * Find terminals by type
     */
    public function findByType(TerminalType $type): array
    {
        return $this->findBy(['type' => $type, 'isActive' => true]);
    }

    /**
     * Find terminals that can accept a specific container type
     */
    public function findByContainerCompatibility(string $containerType, string $containerSize): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.supportedContainerTypes', 'sct')
            ->where('t.isActive = :active')
            ->andWhere('sct.type = :containerType OR sct.type = :allTypes')
            ->andWhere('sct.size = :containerSize OR sct.size = :allSizes')
            ->setParameter('active', true)
            ->setParameter('containerType', $containerType)
            ->setParameter('allTypes', 'ALL')
            ->setParameter('containerSize', $containerSize)
            ->setParameter('allSizes', 'ALL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find terminals with available capacity for a specific date
     */
    public function findWithAvailableCapacity(\DateTime $date): array
    {
        return $this->createQueryBuilder('t')
            ->leftJoin('t.slots', 's', 'WITH', 's.date = :date')
            ->where('t.isActive = :active')
            ->andWhere('(s.assignedCount < s.capacity) OR s.id IS NULL')
            ->setParameter('active', true)
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }
}