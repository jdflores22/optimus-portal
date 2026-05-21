<?php

namespace App\Tests\Unit\Exception;

use App\Entity\ShippingLineTerminalAllocation;
use App\Entity\Terminal;
use App\Exception\InsufficientCapacityException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for InsufficientCapacityException
 * Task 3.2: Test size-specific error response structure
 */
class InsufficientCapacityExceptionTest extends TestCase
{
    /**
     * Test 20ft capacity error code
     * Validates Property 7: Error messages specify container size
     */
    public function testGetErrorCodeFor20ftContainer(): void
    {
        $exception = new InsufficientCapacityException(
            1.0,  // required TEU
            0.0,  // available TEU
            null,
            'Terminal A',
            '20ft'
        );

        $this->assertEquals('INSUFFICIENT_20FT_CAPACITY', $exception->getErrorCode());
    }

    /**
     * Test 40ft capacity error code
     * Validates Property 7: Error messages specify container size
     */
    public function testGetErrorCodeFor40ftContainer(): void
    {
        $exception = new InsufficientCapacityException(
            2.0,  // required TEU
            0.0,  // available TEU
            null,
            'Terminal B',
            '40ft'
        );

        $this->assertEquals('INSUFFICIENT_40FT_CAPACITY', $exception->getErrorCode());
    }

    /**
     * Test fallback error code for TEU-based capacity
     */
    public function testGetErrorCodeForTeuBased(): void
    {
        $exception = new InsufficientCapacityException(
            5.0,  // required TEU
            2.0,  // available TEU
            null,
            'Terminal C'
        );

        $this->assertEquals('INSUFFICIENT_CAPACITY', $exception->getErrorCode());
    }

    /**
     * Test 20ft error message format
     * Validates Requirement 9.1: Error message format
     */
    public function testGetMessageFor20ftContainer(): void
    {
        $exception = new InsufficientCapacityException(
            1.0,  // required TEU (1 container)
            0.0,  // available TEU (0 containers)
            null,
            'Terminal A',
            '20ft'
        );

        $expectedMessage = 'Insufficient 20ft capacity at Terminal A. Required: 1 containers, Available: 0 containers';
        $this->assertEquals($expectedMessage, $exception->getMessage());
    }

    /**
     * Test 40ft error message format
     * Validates Requirement 9.2: Error message format
     */
    public function testGetMessageFor40ftContainer(): void
    {
        $exception = new InsufficientCapacityException(
            2.0,  // required TEU (1 container)
            0.0,  // available TEU (0 containers)
            null,
            'Terminal B',
            '40ft'
        );

        $expectedMessage = 'Insufficient 40ft capacity at Terminal B. Required: 1 containers, Available: 0 containers';
        $this->assertEquals($expectedMessage, $exception->getMessage());
    }

    /**
     * Test error response array structure for 20ft
     * Validates that response includes all required fields
     */
    public function testToArrayFor20ftContainer(): void
    {
        $terminal = $this->createMock(Terminal::class);
        $terminal->method('getId')->willReturn(1);
        $terminal->method('getName')->willReturn('Terminal A');

        $allocation = $this->createMock(ShippingLineTerminalAllocation::class);
        $allocation->method('getId')->willReturn(10);
        $allocation->method('getTerminal')->willReturn($terminal);

        $exception = new InsufficientCapacityException(
            1.0,  // required TEU
            0.0,  // available TEU
            $allocation,
            null,
            '20ft'
        );

        $result = $exception->toArray();

        // Verify error code
        $this->assertEquals('INSUFFICIENT_20FT_CAPACITY', $result['error']);

        // Verify message
        $this->assertStringContainsString('Insufficient 20ft capacity', $result['message']);
        $this->assertStringContainsString('Terminal A', $result['message']);

        // Verify details structure
        $this->assertArrayHasKey('details', $result);
        $details = $result['details'];

        $this->assertEquals(1, $details['terminal_id']);
        $this->assertEquals('Terminal A', $details['terminal_name']);
        $this->assertEquals(10, $details['allocation_id']);
        $this->assertEquals('20ft', $details['container_size']);
        $this->assertEquals(1, $details['required_count']);
        $this->assertEquals(0, $details['available_count']);
    }

    /**
     * Test error response array structure for 40ft
     * Validates that response includes all required fields
     */
    public function testToArrayFor40ftContainer(): void
    {
        $terminal = $this->createMock(Terminal::class);
        $terminal->method('getId')->willReturn(2);
        $terminal->method('getName')->willReturn('Terminal B');

        $allocation = $this->createMock(ShippingLineTerminalAllocation::class);
        $allocation->method('getId')->willReturn(20);
        $allocation->method('getTerminal')->willReturn($terminal);

        $exception = new InsufficientCapacityException(
            2.0,  // required TEU (1 container)
            0.0,  // available TEU (0 containers)
            $allocation,
            null,
            '40ft'
        );

        $result = $exception->toArray();

        // Verify error code
        $this->assertEquals('INSUFFICIENT_40FT_CAPACITY', $result['error']);

        // Verify message
        $this->assertStringContainsString('Insufficient 40ft capacity', $result['message']);
        $this->assertStringContainsString('Terminal B', $result['message']);

        // Verify details structure
        $this->assertArrayHasKey('details', $result);
        $details = $result['details'];

        $this->assertEquals(2, $details['terminal_id']);
        $this->assertEquals('Terminal B', $details['terminal_name']);
        $this->assertEquals(20, $details['allocation_id']);
        $this->assertEquals('40ft', $details['container_size']);
        $this->assertEquals(1, $details['required_count']);
        $this->assertEquals(0, $details['available_count']);
    }

    /**
     * Test container count calculation for 20ft
     */
    public function testRequiredCountCalculationFor20ft(): void
    {
        $exception = new InsufficientCapacityException(
            3.0,  // 3 TEU = 3 containers for 20ft
            1.0,  // 1 TEU = 1 container for 20ft
            null,
            'Terminal A',
            '20ft'
        );

        $this->assertEquals(3, $exception->getRequiredCount());
        $this->assertEquals(1, $exception->getAvailableCount());
    }

    /**
     * Test container count calculation for 40ft
     */
    public function testRequiredCountCalculationFor40ft(): void
    {
        $exception = new InsufficientCapacityException(
            4.0,  // 4 TEU = 2 containers for 40ft
            2.0,  // 2 TEU = 1 container for 40ft
            null,
            'Terminal B',
            '40ft'
        );

        $this->assertEquals(2, $exception->getRequiredCount());
        $this->assertEquals(1, $exception->getAvailableCount());
    }

    /**
     * Test backward compatibility with TEU-based errors
     */
    public function testBackwardCompatibilityWithTeuBased(): void
    {
        $exception = new InsufficientCapacityException(
            5.0,  // required TEU
            2.0,  // available TEU
            null,
            'Terminal C'
        );

        $result = $exception->toArray();

        // Should use TEU-based fields
        $this->assertEquals('INSUFFICIENT_CAPACITY', $result['error']);
        $this->assertArrayHasKey('details', $result);
        
        $details = $result['details'];
        $this->assertArrayHasKey('required_teu', $details);
        $this->assertArrayHasKey('available_teu', $details);
        $this->assertArrayHasKey('shortage_teu', $details);
        $this->assertArrayNotHasKey('container_size', $details);
    }

    /**
     * Test HTTP status code
     */
    public function testHttpStatusCode(): void
    {
        $exception = new InsufficientCapacityException(
            1.0,
            0.0,
            null,
            'Terminal A',
            '20ft'
        );

        $this->assertEquals(400, $exception->getCode());
    }
}
