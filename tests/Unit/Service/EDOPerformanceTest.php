<?php

namespace App\Tests\Unit\Service;

use App\Entity\ElectronicDeliveryOrder;
use App\Entity\Manifest;
use App\Entity\Payment;
use App\Service\EDOService;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tests for eDO performance optimizations
 * 
 * Requirements: 14.3, 14.5
 */
class EDOPerformanceTest extends TestCase
{
    private CacheInterface $cache;
    private EDOService $edoService;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(CacheInterface::class);
        
        // Create EDOService with mocked dependencies
        $entityManager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $documentService = $this->createMock(\App\Service\DocumentService::class);
        $auditService = $this->createMock(\App\Service\AuditService::class);
        $activityLogService = $this->createMock(\App\Service\ActivityLogService::class);
        $notificationService = $this->createMock(\App\Service\ManifestNotificationService::class);
        
        $this->edoService = new EDOService(
            $entityManager,
            $documentService,
            $auditService,
            $activityLogService,
            $notificationService,
            $this->cache
        );
    }

    /**
     * Test that PDF caching is implemented
     * Requirement 14.3: Cache generated eDO PDF files for faster access
     */
    public function testGetCachedEDOPDFUsesCaching(): void
    {
        $edo = $this->createMock(ElectronicDeliveryOrder::class);
        $edo->method('getId')->willReturn(123);
        $edo->method('getPdfPath')->willReturn(__DIR__ . '/../../fixtures/test.pdf');
        
        // Create a test PDF file
        $testPdfPath = __DIR__ . '/../../fixtures/test.pdf';
        if (!is_dir(dirname($testPdfPath))) {
            mkdir(dirname($testPdfPath), 0777, true);
        }
        file_put_contents($testPdfPath, 'test pdf content');
        
        $this->cache->expects($this->once())
            ->method('get')
            ->with('edo_pdf_123', $this->isType('callable'))
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())
                    ->method('expiresAfter')
                    ->with(86400); // 24 hours
                return $callback($item);
            });
        
        $result = $this->edoService->getCachedEDOPDF($edo);
        
        $this->assertEquals('test pdf content', $result);
        
        // Cleanup
        unlink($testPdfPath);
        rmdir(dirname($testPdfPath));
    }

    /**
     * Test that cache invalidation works
     * Requirement 14.5: Set appropriate cache TTL
     */
    public function testInvalidateEDOPDFCacheDeletesCacheEntry(): void
    {
        $edo = $this->createMock(ElectronicDeliveryOrder::class);
        $edo->method('getId')->willReturn(456);
        
        $this->cache->expects($this->once())
            ->method('delete')
            ->with('edo_pdf_456');
        
        $this->edoService->invalidateEDOPDFCache($edo);
    }

    /**
     * Test that cache TTL is set to 24 hours
     * Requirement 14.5: Set appropriate cache TTL
     */
    public function testCacheTTLIsSetTo24Hours(): void
    {
        $edo = $this->createMock(ElectronicDeliveryOrder::class);
        $edo->method('getId')->willReturn(789);
        $edo->method('getPdfPath')->willReturn(__DIR__ . '/../../fixtures/test2.pdf');
        
        // Create a test PDF file
        $testPdfPath = __DIR__ . '/../../fixtures/test2.pdf';
        if (!is_dir(dirname($testPdfPath))) {
            mkdir(dirname($testPdfPath), 0777, true);
        }
        file_put_contents($testPdfPath, 'test pdf content 2');
        
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) {
                $item = $this->createMock(ItemInterface::class);
                $item->expects($this->once())
                    ->method('expiresAfter')
                    ->with(86400); // Verify 24 hours = 86400 seconds
                return $callback($item);
            });
        
        $this->edoService->getCachedEDOPDF($edo);
        
        // Cleanup
        unlink($testPdfPath);
        rmdir(dirname($testPdfPath));
    }
}
