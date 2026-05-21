<?php

namespace App\Utility;

use App\Repository\ElectronicDeliveryOrderRepository;

/**
 * Utility class for generating unique eDO numbers
 * Format: EDO-YYYYMM-XXXX (Global continuous sequence per month)
 */
class EDONumberGenerator
{
    public function __construct(
        private ElectronicDeliveryOrderRepository $edoRepository
    ) {
    }

    /**
     * Generate a unique eDO number with global continuous sequence
     *
     * @param string $containerNumber The container number (for compatibility, not used in numbering)
     * @return string The generated unique eDO number
     */
    public function generate(string $containerNumber = ''): string
    {
        $sequence = $this->getNextGlobalSequenceNumber();
        $yearMonth = (new \DateTime())->format('Ym');
        
        $edoNumber = sprintf('EDO-%s-%s', 
            $yearMonth, 
            str_pad((string)$sequence, 4, '0', STR_PAD_LEFT)
        );

        // Ensure uniqueness - if by any chance this number exists, increment sequence
        while ($this->edoRepository->findOneBy(['edoNumber' => $edoNumber])) {
            $sequence++;
            $edoNumber = sprintf('EDO-%s-%s', 
                $yearMonth, 
                str_pad((string)$sequence, 4, '0', STR_PAD_LEFT)
            );
        }

        return $edoNumber;
    }

    /**
     * Get the next global sequence number for the current month
     *
     * @return int The next sequence number
     */
    private function getNextGlobalSequenceNumber(): int
    {
        $yearMonth = (new \DateTime())->format('Y-m');
        $nextMonth = (new \DateTime('first day of next month'))->format('Y-m-d');
        
        // Count all eDOs created this month (global sequence)
        $count = $this->edoRepository->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.generatedAt >= :monthStart')
            ->andWhere('e.generatedAt < :nextMonth')
            ->setParameter('monthStart', $yearMonth . '-01')
            ->setParameter('nextMonth', $nextMonth)
            ->getQuery()
            ->getSingleScalarResult();

        return (int)$count + 1;
    }

    /**
     * Get the latest eDO number for reference
     *
     * @return string|null The latest eDO number or null if none exists
     */
    public function getLatestEDONumber(): ?string
    {
        $latestEdo = $this->edoRepository->createQueryBuilder('e')
            ->select('e.edoNumber')
            ->orderBy('e.generatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $latestEdo ? $latestEdo['edoNumber'] : null;
    }
}
