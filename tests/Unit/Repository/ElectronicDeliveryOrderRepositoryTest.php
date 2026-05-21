<?php

namespace App\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;

/**
 * Tests for ElectronicDeliveryOrderRepository performance optimizations
 * 
 * Requirements: 14.1, 14.4
 * 
 * Note: These tests verify the query structure through code inspection.
 * Integration tests should verify actual query performance.
 */
class ElectronicDeliveryOrderRepositoryTest extends TestCase
{
    /**
     * Test that getPendingReleases method exists and has correct signature
     * Requirement 14.1: Use query optimization for pending eDO retrieval
     */
    public function testGetPendingReleasesMethodExists(): void
    {
        $reflection = new \ReflectionClass(\App\Repository\ElectronicDeliveryOrderRepository::class);
        
        $this->assertTrue($reflection->hasMethod('getPendingReleases'));
        
        $method = $reflection->getMethod('getPendingReleases');
        $this->assertTrue($method->isPublic());
        
        $parameters = $method->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertEquals('page', $parameters[0]->getName());
        $this->assertEquals('perPage', $parameters[1]->getName());
    }

    /**
     * Test that getPendingReleases uses eager loading by inspecting the code
     * Requirement 14.4: Implement eager loading for related entities
     */
    public function testGetPendingReleasesUsesEagerLoading(): void
    {
        $reflection = new \ReflectionClass(\App\Repository\ElectronicDeliveryOrderRepository::class);
        $method = $reflection->getMethod('getPendingReleases');
        
        // Get the method source code
        $filename = $reflection->getFileName();
        $startLine = $method->getStartLine() - 1;
        $endLine = $method->getEndLine();
        $length = $endLine - $startLine;
        
        $source = file($filename);
        $methodCode = implode('', array_slice($source, $startLine, $length));
        
        // Verify eager loading joins are present
        $this->assertStringContainsString('leftJoin', $methodCode, 'Method should use leftJoin for eager loading');
        $this->assertStringContainsString('edo.manifest', $methodCode, 'Should join manifest');
        $this->assertStringContainsString('edo.payment', $methodCode, 'Should join payment');
        $this->assertStringContainsString('addSelect', $methodCode, 'Should use addSelect to fetch related entities');
    }

    /**
     * Test that findWithRelations method uses eager loading
     * Requirement 14.4: Implement eager loading for related entities
     */
    public function testFindWithRelationsUsesEagerLoading(): void
    {
        $reflection = new \ReflectionClass(\App\Repository\ElectronicDeliveryOrderRepository::class);
        
        $this->assertTrue($reflection->hasMethod('findWithRelations'));
        
        $method = $reflection->getMethod('findWithRelations');
        
        // Get the method source code
        $filename = $reflection->getFileName();
        $startLine = $method->getStartLine() - 1;
        $endLine = $method->getEndLine();
        $length = $endLine - $startLine;
        
        $source = file($filename);
        $methodCode = implode('', array_slice($source, $startLine, $length));
        
        // Verify eager loading joins are present
        $this->assertStringContainsString('leftJoin', $methodCode, 'Method should use leftJoin for eager loading');
        $this->assertStringContainsString('addSelect', $methodCode, 'Should use addSelect to fetch related entities');
    }
}
