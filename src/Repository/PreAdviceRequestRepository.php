<?php

namespace App\Repository;

use App\Entity\PreAdviceRequest;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\TerminalType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PreAdviceRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PreAdviceRequest::class);
    }

    /**
     * Get pre-advice statistics for a date range
     */
    public function getStatistics(\DateTime $startDate, \DateTime $endDate): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('
                COUNT(p.id) as total_requests,
                COUNT(CASE WHEN p.status = :pending THEN 1 END) as pending_requests,
                COUNT(CASE WHEN p.status = :verified THEN 1 END) as verified_requests,
                COUNT(CASE WHEN p.status = :rejected THEN 1 END) as rejected_requests,
                COUNT(CASE WHEN p.status = :completed THEN 1 END) as completed_requests
            ')
            ->where('p.createdAt BETWEEN :startDate AND :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('pending', PreAdviceStatus::PENDING)
            ->setParameter('verified', PreAdviceStatus::VERIFIED)
            ->setParameter('rejected', PreAdviceStatus::REJECTED)
            ->setParameter('completed', PreAdviceStatus::COMPLETED);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Get approval rate statistics
     */
    public function getApprovalRates(\DateTime $startDate, \DateTime $endDate): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('
                COUNT(p.id) as total_processed,
                COUNT(CASE WHEN p.status IN (:approved_statuses) THEN 1 END) as approved_count,
                COUNT(CASE WHEN p.status = :rejected THEN 1 END) as rejected_count
            ')
            ->where('p.createdAt BETWEEN :startDate AND :endDate')
            ->andWhere('p.status IN (:all_processed_statuses)')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('approved_statuses', [PreAdviceStatus::VERIFIED, PreAdviceStatus::COMPLETED])
            ->setParameter('rejected', PreAdviceStatus::REJECTED)
            ->setParameter('all_processed_statuses', [PreAdviceStatus::VERIFIED, PreAdviceStatus::REJECTED, PreAdviceStatus::COMPLETED]);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Get terminal utilization statistics
     */
    public function getTerminalUtilization(\DateTime $startDate, \DateTime $endDate): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('
                t.name as terminal_name,
                t.type as terminal_type,
                COUNT(p.id) as request_count,
                COUNT(CASE WHEN p.status IN (:completed_statuses) THEN 1 END) as completed_count
            ')
            ->join('p.selectedTerminal', 't')
            ->where('p.createdAt BETWEEN :startDate AND :endDate')
            ->groupBy('t.id, t.name, t.type')
            ->orderBy('request_count', 'DESC')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('completed_statuses', [PreAdviceStatus::VERIFIED, PreAdviceStatus::COMPLETED]);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get daily trends for a date range
     */
    public function getDailyTrends(\DateTime $startDate, \DateTime $endDate): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('
                DATE(p.createdAt) as date,
                COUNT(p.id) as total_requests,
                COUNT(CASE WHEN p.status = :verified THEN 1 END) as verified_requests,
                COUNT(CASE WHEN p.status = :rejected THEN 1 END) as rejected_requests
            ')
            ->where('p.createdAt BETWEEN :startDate AND :endDate')
            ->groupBy('DATE(p.createdAt)')
            ->orderBy('date', 'ASC')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('verified', PreAdviceStatus::VERIFIED)
            ->setParameter('rejected', PreAdviceStatus::REJECTED);

        return $qb->getQuery()->getResult();
    }

    /**
     * Get processing time statistics
     */
    public function getProcessingTimeStats(\DateTime $startDate, \DateTime $endDate): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('
                AVG(TIMESTAMPDIFF(HOUR, p.createdAt, p.verifiedAt)) as avg_processing_hours,
                MIN(TIMESTAMPDIFF(HOUR, p.createdAt, p.verifiedAt)) as min_processing_hours,
                MAX(TIMESTAMPDIFF(HOUR, p.createdAt, p.verifiedAt)) as max_processing_hours
            ')
            ->where('p.createdAt BETWEEN :startDate AND :endDate')
            ->andWhere('p.verifiedAt IS NOT NULL')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate);

        return $qb->getQuery()->getSingleResult();
    }

    /**
     * Get requests by terminal type
     */
    public function getRequestsByTerminalType(\DateTime $startDate, \DateTime $endDate): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('
                t.type as terminal_type,
                COUNT(p.id) as request_count,
                COUNT(CASE WHEN p.status = :verified THEN 1 END) as verified_count,
                COUNT(CASE WHEN p.status = :rejected THEN 1 END) as rejected_count
            ')
            ->join('p.selectedTerminal', 't')
            ->where('p.createdAt BETWEEN :startDate AND :endDate')
            ->groupBy('t.type')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->setParameter('verified', PreAdviceStatus::VERIFIED)
            ->setParameter('rejected', PreAdviceStatus::REJECTED);

        return $qb->getQuery()->getResult();
    }
}