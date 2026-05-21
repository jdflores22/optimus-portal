<?php

namespace App\Repository;

use App\Entity\Terminal;
use App\Entity\TerminalSlot;
use App\Entity\Enum\SlotStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TerminalSlot>
 */
class TerminalSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TerminalSlot::class);
    }

    /**
     * Find slots by terminal and date range
     */
    public function findByTerminalAndDateRange(Terminal $terminal, \DateTime $startDate, \DateTime $endDate): array
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.terminal = :terminal')
            ->andWhere('ts.date >= :startDate')
            ->andWhere('ts.date <= :endDate')
            ->setParameter('terminal', $terminal)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('ts.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find available slots by terminal and date range
     */
    public function findAvailableSlots(Terminal $terminal, \DateTime $startDate, \DateTime $endDate): array
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.terminal = :terminal')
            ->andWhere('ts.date >= :startDate')
            ->andWhere('ts.date <= :endDate')
            ->andWhere('ts.status = :status')
            ->andWhere('ts.assignedCount < ts.capacity')
            ->setParameter('terminal', $terminal)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('status', SlotStatus::AVAILABLE)
            ->orderBy('ts.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find slot by terminal and specific date
     */
    public function findByTerminalAndDate(Terminal $terminal, \DateTime $date): ?TerminalSlot
    {
        return $this->findOneBy(['terminal' => $terminal, 'date' => $date]);
    }

    /**
     * Find slots with available capacity
     */
    public function findSlotsWithAvailableCapacity(\DateTime $startDate, \DateTime $endDate): array
    {
        return $this->createQueryBuilder('ts')
            ->where('ts.date >= :startDate')
            ->andWhere('ts.date <= :endDate')
            ->andWhere('ts.status = :status')
            ->andWhere('ts.assignedCount < ts.capacity')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('status', SlotStatus::AVAILABLE)
            ->orderBy('ts.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get slot utilization statistics for a date range
     */
    public function getUtilizationStats(\DateTime $startDate, \DateTime $endDate): array
    {
        return $this->createQueryBuilder('ts')
            ->select('
                COUNT(ts.id) as totalSlots,
                SUM(ts.capacity) as totalCapacity,
                SUM(ts.assignedCount) as totalAssigned,
                AVG(ts.assignedCount / ts.capacity * 100) as avgUtilization
            ')
            ->where('ts.date >= :startDate')
            ->andWhere('ts.date <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->getQuery()
            ->getSingleResult();
    }

    /**
     * Find slots by status
     */
    public function findByStatus(SlotStatus $status): array
    {
        return $this->findBy(['status' => $status]);
    }
}