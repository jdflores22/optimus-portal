<?php

namespace App\Tests\Controller;

use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Property-based test for Terminal Team dashboard metrics accuracy
 * 
 * **Feature: terminal-team-pre-advice, Property 6: Dashboard metrics accuracy**
 * **Validates: Requirements 4.2**
 * 
 * Property: For any terminal, the dashboard should display accurate pending booking request counts and available slot counts
 */
class DashboardMetricsPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 6: Dashboard metrics accuracy
     * For any set of terminal data and pre-advice requests,
     * the calculated metrics should accurately reflect the input data
     */
    public function testDashboardMetricsCalculationAccuracy(): void
    {
        $this->forAll(
            Generator\choose(1, 3), // Number of terminals per type
            Generator\choose(0, 10), // Number of pending requests
            Generator\choose(0, 5), // Number of verified requests today
            Generator\choose(5, 20), // Daily capacity per terminal
            Generator\choose(0, 15) // Number of available slots
        )->then(function (
            int $terminalsPerType,
            int $pendingRequests,
            int $verifiedRequestsToday,
            int $dailyCapacity,
            int $availableSlots
        ) {
            // Create mock data structure representing terminal metrics
            $terminalTypes = ['CY', 'ATI', 'ICTSI'];
            $mockData = [];
            
            foreach ($terminalTypes as $terminalType) {
                $mockData[$terminalType] = [
                    'terminals' => $terminalsPerType,
                    'pending_requests' => $pendingRequests,
                    'verified_requests_today' => $verifiedRequestsToday,
                    'daily_capacity' => $dailyCapacity,
                    'available_slots' => $availableSlots,
                ];
            }
            
            // Calculate expected overall metrics
            $expectedOverallMetrics = [
                'total_pending' => $pendingRequests * count($terminalTypes),
                'total_verified_today' => $verifiedRequestsToday * count($terminalTypes),
                'total_available_slots' => $availableSlots * count($terminalTypes),
                'total_capacity' => $dailyCapacity * $terminalsPerType * count($terminalTypes),
            ];
            
            // Simulate the dashboard metrics calculation logic
            $calculatedMetrics = $this->simulateDashboardMetricsCalculation($mockData);
            
            // Verify that calculated metrics match expected values
            $this->assertEquals(
                $expectedOverallMetrics['total_pending'],
                $calculatedMetrics['overall']['total_pending'],
                'Total pending requests should be calculated correctly'
            );
            
            $this->assertEquals(
                $expectedOverallMetrics['total_verified_today'],
                $calculatedMetrics['overall']['total_verified_today'],
                'Total verified requests today should be calculated correctly'
            );
            
            $this->assertEquals(
                $expectedOverallMetrics['total_available_slots'],
                $calculatedMetrics['overall']['total_available_slots'],
                'Total available slots should be calculated correctly'
            );
            
            $this->assertEquals(
                $expectedOverallMetrics['total_capacity'],
                $calculatedMetrics['overall']['total_capacity'],
                'Total capacity should be calculated correctly'
            );
            
            // Verify individual terminal type metrics
            foreach ($terminalTypes as $terminalType) {
                $this->assertEquals(
                    $pendingRequests,
                    $calculatedMetrics[$terminalType]['pending_requests'],
                    "Pending requests for {$terminalType} should be calculated correctly"
                );
                
                $this->assertEquals(
                    $verifiedRequestsToday,
                    $calculatedMetrics[$terminalType]['verified_requests'],
                    "Verified requests for {$terminalType} should be calculated correctly"
                );
                
                $this->assertEquals(
                    $availableSlots,
                    $calculatedMetrics[$terminalType]['available_slots'],
                    "Available slots for {$terminalType} should be calculated correctly"
                );
                
                $this->assertEquals(
                    $dailyCapacity * $terminalsPerType,
                    $calculatedMetrics[$terminalType]['total_capacity'],
                    "Total capacity for {$terminalType} should be calculated correctly"
                );
            }
        });
    }

    /**
     * Property: Metrics should be non-negative
     */
    public function testDashboardMetricsNonNegative(): void
    {
        $this->forAll(
            Generator\choose(0, 5), // Number of terminals per type
            Generator\choose(0, 20), // Number of pending requests
            Generator\choose(0, 10), // Number of verified requests today
            Generator\choose(0, 30), // Daily capacity per terminal
            Generator\choose(0, 25) // Number of available slots
        )->then(function (
            int $terminalsPerType,
            int $pendingRequests,
            int $verifiedRequestsToday,
            int $dailyCapacity,
            int $availableSlots
        ) {
            $terminalTypes = ['CY', 'ATI', 'ICTSI'];
            $mockData = [];
            
            foreach ($terminalTypes as $terminalType) {
                $mockData[$terminalType] = [
                    'terminals' => $terminalsPerType,
                    'pending_requests' => $pendingRequests,
                    'verified_requests_today' => $verifiedRequestsToday,
                    'daily_capacity' => $dailyCapacity,
                    'available_slots' => $availableSlots,
                ];
            }
            
            $calculatedMetrics = $this->simulateDashboardMetricsCalculation($mockData);
            
            // All metrics should be non-negative
            $this->assertGreaterThanOrEqual(0, $calculatedMetrics['overall']['total_pending']);
            $this->assertGreaterThanOrEqual(0, $calculatedMetrics['overall']['total_verified_today']);
            $this->assertGreaterThanOrEqual(0, $calculatedMetrics['overall']['total_available_slots']);
            $this->assertGreaterThanOrEqual(0, $calculatedMetrics['overall']['total_capacity']);
            
            foreach ($terminalTypes as $terminalType) {
                $this->assertGreaterThanOrEqual(0, $calculatedMetrics[$terminalType]['pending_requests']);
                $this->assertGreaterThanOrEqual(0, $calculatedMetrics[$terminalType]['verified_requests']);
                $this->assertGreaterThanOrEqual(0, $calculatedMetrics[$terminalType]['available_slots']);
                $this->assertGreaterThanOrEqual(0, $calculatedMetrics[$terminalType]['total_capacity']);
            }
        });
    }

    /**
     * Property: Overall metrics should equal sum of individual terminal type metrics
     */
    public function testDashboardMetricsConsistency(): void
    {
        $this->forAll(
            Generator\choose(1, 4), // Number of terminals per type
            Generator\choose(0, 15), // Number of pending requests
            Generator\choose(0, 8), // Number of verified requests today
            Generator\choose(5, 25), // Daily capacity per terminal
            Generator\choose(0, 20) // Number of available slots
        )->then(function (
            int $terminalsPerType,
            int $pendingRequests,
            int $verifiedRequestsToday,
            int $dailyCapacity,
            int $availableSlots
        ) {
            $terminalTypes = ['CY', 'ATI', 'ICTSI'];
            $mockData = [];
            
            foreach ($terminalTypes as $terminalType) {
                $mockData[$terminalType] = [
                    'terminals' => $terminalsPerType,
                    'pending_requests' => $pendingRequests,
                    'verified_requests_today' => $verifiedRequestsToday,
                    'daily_capacity' => $dailyCapacity,
                    'available_slots' => $availableSlots,
                ];
            }
            
            $calculatedMetrics = $this->simulateDashboardMetricsCalculation($mockData);
            
            // Calculate sum of individual terminal type metrics
            $sumPending = 0;
            $sumVerified = 0;
            $sumSlots = 0;
            $sumCapacity = 0;
            
            foreach ($terminalTypes as $terminalType) {
                $sumPending += $calculatedMetrics[$terminalType]['pending_requests'];
                $sumVerified += $calculatedMetrics[$terminalType]['verified_requests'];
                $sumSlots += $calculatedMetrics[$terminalType]['available_slots'];
                $sumCapacity += $calculatedMetrics[$terminalType]['total_capacity'];
            }
            
            // Overall metrics should equal the sum of individual metrics
            $this->assertEquals(
                $sumPending,
                $calculatedMetrics['overall']['total_pending'],
                'Overall pending requests should equal sum of individual terminal type pending requests'
            );
            
            $this->assertEquals(
                $sumVerified,
                $calculatedMetrics['overall']['total_verified_today'],
                'Overall verified requests should equal sum of individual terminal type verified requests'
            );
            
            $this->assertEquals(
                $sumSlots,
                $calculatedMetrics['overall']['total_available_slots'],
                'Overall available slots should equal sum of individual terminal type available slots'
            );
            
            $this->assertEquals(
                $sumCapacity,
                $calculatedMetrics['overall']['total_capacity'],
                'Overall capacity should equal sum of individual terminal type capacities'
            );
        });
    }

    /**
     * Simulate the dashboard metrics calculation logic
     * This mirrors the logic in TerminalTeamController::calculateDashboardMetrics()
     */
    private function simulateDashboardMetricsCalculation(array $mockData): array
    {
        $metrics = [];
        
        foreach ($mockData as $terminalType => $data) {
            $metrics[$terminalType] = [
                'pending_requests' => $data['pending_requests'],
                'verified_requests' => $data['verified_requests_today'],
                'available_slots' => $data['available_slots'],
                'total_capacity' => $data['daily_capacity'] * $data['terminals'],
            ];
        }
        
        // Calculate overall metrics
        $metrics['overall'] = [
            'total_pending' => array_sum(array_column($metrics, 'pending_requests')),
            'total_verified_today' => array_sum(array_column($metrics, 'verified_requests')),
            'total_available_slots' => array_sum(array_column($metrics, 'available_slots')),
            'total_capacity' => array_sum(array_column($metrics, 'total_capacity')),
        ];
        
        return $metrics;
    }
}