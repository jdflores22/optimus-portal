<?php

namespace App\Service;

use App\Entity\Container;

interface DwellTimeCalculatorInterface
{
    /**
     * Calculate dwell time in days from arrival date, accounting for pause periods
     */
    public function calculateDwellTime(
        \DateTime $arrivalDate, 
        array $pausePeriods = [], 
        ?\DateTime $endDate = null
    ): int;
    
    /**
     * Calculate the next notification date for a container
     */
    public function calculateNextNotificationDate(Container $container): ?\DateTime;
    
    /**
     * Calculate the automatic return date for a container
     */
    public function calculateReturnDate(Container $container): \DateTime;
    
    /**
     * Get total pause duration in days for a container
     */
    public function getTotalPauseDuration(Container $container): int;
}