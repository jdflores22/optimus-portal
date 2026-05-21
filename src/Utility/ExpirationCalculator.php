<?php

namespace App\Utility;

/**
 * Utility class for calculating expired days with timezone handling
 * 
 * Requirements: 4.4
 */
class ExpirationCalculator
{
    /**
     * Calculate the number of days between expiration date and current date
     * 
     * @param \DateTimeInterface $expirationDate The expiration date
     * @param \DateTimeInterface|null $currentDate The current date (defaults to now)
     * @return int Number of days expired (0 if not expired, positive if expired)
     */
    public function calculateExpiredDays(\DateTimeInterface $expirationDate, ?\DateTimeInterface $currentDate = null): int
    {
        // Use current date/time if not provided
        if ($currentDate === null) {
            $currentDate = new \DateTime('now', new \DateTimeZone('UTC'));
        }

        // Ensure both dates are in UTC for consistent comparison
        $expirationDateUTC = $this->convertToUTC($expirationDate);
        $currentDateUTC = $this->convertToUTC($currentDate);

        // If not expired yet, return 0
        if ($currentDateUTC <= $expirationDateUTC) {
            return 0;
        }

        // Calculate the difference in days
        $interval = $currentDateUTC->diff($expirationDateUTC);
        
        // Return the number of days (always positive for expired eDOs)
        return (int) $interval->days;
    }

    /**
     * Convert a DateTime to UTC timezone
     * 
     * @param \DateTimeInterface $dateTime
     * @return \DateTime
     */
    private function convertToUTC(\DateTimeInterface $dateTime): \DateTime
    {
        // If it's already a DateTime object, clone it to avoid modifying the original
        if ($dateTime instanceof \DateTime) {
            $utcDate = clone $dateTime;
        } else {
            // Convert DateTimeImmutable to DateTime
            $utcDate = \DateTime::createFromFormat(
                'Y-m-d H:i:s',
                $dateTime->format('Y-m-d H:i:s'),
                $dateTime->getTimezone()
            );
        }

        // Convert to UTC
        $utcDate->setTimezone(new \DateTimeZone('UTC'));

        return $utcDate;
    }
}
