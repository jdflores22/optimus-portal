<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\DwellTimeEvent;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Service\DwellTimeAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DwellTimeAuditIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private DwellTimeAuditService $auditService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->auditService = $container->get(DwellTimeAuditService::class);
    }

    public function testQueryEventsReturnsResults(): void
    {
        // This is a basic smoke test to ensure the service is properly configured
        $criteria = [
            'limit' => 10,
            'offset' => 0
        ];

        $events = $this->auditService->queryEvents($criteria);
        
        $this->assertIsArray($events);
        // We don't assert count since database may be empty
    }

    public function testCountEventsReturnsInteger(): void
    {
        $criteria = [];
        
        $count = $this->auditService->countEvents($criteria);
        
        $this->assertIsInt($count);
        $this->assertGreaterThanOrEqual(0, $count);
    }

    public function testGenerateReportReturnsStructuredData(): void
    {
        $fromDate = new \DateTime('2024-01-01');
        $toDate = new \DateTime('2024-12-31');
        
        $report = $this->auditService->generateReport($fromDate, $toDate);
        
        $this->assertIsArray($report);
        $this->assertArrayHasKey('date_range', $report);
        $this->assertArrayHasKey('total_events', $report);
        $this->assertArrayHasKey('events_by_type', $report);
        $this->assertArrayHasKey('statistics', $report);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
