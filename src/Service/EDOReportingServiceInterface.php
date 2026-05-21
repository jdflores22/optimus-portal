<?php

namespace App\Service;

use App\Entity\User;

interface EDOReportingServiceInterface
{
    /**
     * Get average time from eDO generation to release in hours
     *
     * @param \DateTimeInterface $startDate
     * @param \DateTimeInterface $endDate
     * @param User|null $releasedBy Filter by specific SYSTEM_ADMIN user
     * @return float Average release time in hours
     */
    public function getAverageReleaseTime(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        ?User $releasedBy = null
    ): float;

    /**
     * Get eDOs released per day
     *
     * @param \DateTimeInterface $startDate
     * @param \DateTimeInterface $endDate
     * @param User|null $releasedBy Filter by specific SYSTEM_ADMIN user
     * @return array Array of ['date' => 'Y-m-d', 'count' => int]
     */
    public function getEDOsReleasedPerDay(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate,
        ?User $releasedBy = null
    ): array;

    /**
     * Get rejected eDOs with reasons
     *
     * @param \DateTimeInterface $startDate
     * @param \DateTimeInterface $endDate
     * @return array Array of rejected eDO data with reasons
     */
    public function getRejectedEDOs(
        \DateTimeInterface $startDate,
        \DateTimeInterface $endDate
    ): array;

    /**
     * Get pending eDO count by age buckets
     *
     * @return array Array with keys '0-24h', '24-48h', '48h+' and counts
     */
    public function getPendingEDOsByAge(): array;

    /**
     * Export report data to CSV format
     *
     * @param array $reportData
     * @return string CSV file path
     */
    public function exportToCSV(array $reportData): string;

    /**
     * Export report data to PDF format
     *
     * @param array $reportData
     * @return string PDF file path
     */
    public function exportToPDF(array $reportData): string;
}
