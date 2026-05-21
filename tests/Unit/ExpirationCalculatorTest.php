<?php

namespace App\Tests\Unit;

use App\Utility\ExpirationCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Expiration Calculator
 * 
 * Tests Requirements: 4.4
 */
class ExpirationCalculatorTest extends TestCase
{
    private ExpirationCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ExpirationCalculator();
    }

    /**
     * Test calculation of expired days for past date
     * Validates: Requirement 4.4
     */
    public function testCalculateExpiredDaysForPastDate(): void
    {
        $expirationDate = new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $currentDate = new \DateTime('2026-01-06 00:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->calculateExpiredDays($expirationDate, $currentDate);

        $this->assertEquals(5, $result, 'Should calculate 5 expired days');
    }

    /**
     * Test calculation returns 0 for future date
     * Validates: Requirement 4.4
     */
    public function testCalculateExpiredDaysForFutureDate(): void
    {
        $expirationDate = new \DateTime('2026-01-10 00:00:00', new \DateTimeZone('UTC'));
        $currentDate = new \DateTime('2026-01-05 00:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->calculateExpiredDays($expirationDate, $currentDate);

        $this->assertEquals(0, $result, 'Should return 0 for future expiration date');
    }

    /**
     * Test calculation returns 0 for same date
     * Validates: Requirement 4.4
     */
    public function testCalculateExpiredDaysForSameDate(): void
    {
        $expirationDate = new \DateTime('2026-01-05 12:00:00', new \DateTimeZone('UTC'));
        $currentDate = new \DateTime('2026-01-05 12:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->calculateExpiredDays($expirationDate, $currentDate);

        $this->assertEquals(0, $result, 'Should return 0 when dates are equal');
    }

    /**
     * Test timezone handling - different timezones should be normalized to UTC
     * Validates: Requirement 4.4
     */
    public function testCalculateExpiredDaysHandlesTimezones(): void
    {
        // Expiration date in EST (UTC-5)
        $expirationDate = new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('America/New_York'));
        
        // Current date in PST (UTC-8)
        $currentDate = new \DateTime('2026-01-06 00:00:00', new \DateTimeZone('America/Los_Angeles'));

        $result = $this->calculator->calculateExpiredDays($expirationDate, $currentDate);

        // Both dates should be converted to UTC for comparison
        $this->assertGreaterThanOrEqual(4, $result, 'Should handle timezone conversion correctly');
        $this->assertLessThanOrEqual(6, $result, 'Should handle timezone conversion correctly');
    }

    /**
     * Test calculation with DateTimeImmutable
     * Validates: Requirement 4.4
     */
    public function testCalculateExpiredDaysWithDateTimeImmutable(): void
    {
        $expirationDate = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
        $currentDate = new \DateTimeImmutable('2026-01-04 00:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->calculateExpiredDays($expirationDate, $currentDate);

        $this->assertEquals(3, $result, 'Should work with DateTimeImmutable objects');
    }

    /**
     * Test calculation uses current date when not provided
     * Validates: Requirement 4.4
     */
    public function testCalculateExpiredDaysUsesCurrentDateWhenNotProvided(): void
    {
        // Set expiration date to 2 days ago
        $expirationDate = new \DateTime('-2 days', new \DateTimeZone('UTC'));

        $result = $this->calculator->calculateExpiredDays($expirationDate);

        $this->assertEquals(2, $result, 'Should use current date when not provided');
    }

    /**
     * Test calculation for large number of expired days
     * Validates: Requirement 4.4
     */
    public function testCalculateExpiredDaysForLargeNumberOfDays(): void
    {
        $expirationDate = new \DateTime('2025-01-01 00:00:00', new \DateTimeZone('UTC'));
        $currentDate = new \DateTime('2026-01-01 00:00:00', new \DateTimeZone('UTC'));

        $result = $this->calculator->calculateExpiredDays($expirationDate, $currentDate);

        $this->assertEquals(365, $result, 'Should correctly calculate large number of expired days');
    }
}
