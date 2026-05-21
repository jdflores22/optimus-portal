<?php

namespace App\Tests\Unit\Service;

use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\StaffUser;
use App\Service\ContainerStatusService;
use App\Service\DwellTimeServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContainerStatusServiceTest extends TestCase
{
    private ContainerStatusService $service;
    private MockObject|EntityManagerInterface $entityManager;
    private MockObject|DwellTimeServiceInterface $dwellTimeService;
    private MockObject|LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->dwellTimeService = $this->createMock(DwellTimeServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ContainerStatusService(
            $this->entityManager,
            $this->dwellTimeService,
            $this->logger
        );
    }

    public function testChangeStatusSuccess(): void
    {
        $container = new Container();
        $container->setContainerNumber('TEST123');
        $container->setStatus(ContainerStatus::AT_TERMINAL);

        $user = new StaffUser();
        $newStatus = ContainerStatus::ALERT;

        // Expect dwell time service to be called
        $this->dwellTimeService->expects($this->once())
            ->method('handleStatusChange')
            ->with($container, ContainerStatus::AT_TERMINAL, $newStatus, $user);

        // Expect entity manager flush
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->changeStatus($container, $newStatus, $user, 'Investigation required');

        $this->assertEquals($newStatus, $container->getStatus());
    }

    public function testChangeStatusSameStatus(): void
    {
        $container = new Container();
        $container->setContainerNumber('TEST123');
        $container->setStatus(ContainerStatus::ALERT);

        $user = new StaffUser();

        // Should not call dwell time service for same status
        $this->dwellTimeService->expects($this->never())
            ->method('handleStatusChange');

        // Should not flush for same status
        $this->entityManager->expects($this->never())
            ->method('flush');

        $this->service->changeStatus($container, ContainerStatus::ALERT, $user);

        $this->assertEquals(ContainerStatus::ALERT, $container->getStatus());
    }

    public function testBatchChangeStatusSuccess(): void
    {
        $container1 = new Container();
        $container1->setContainerNumber('TEST123');
        $container1->setStatus(ContainerStatus::AT_TERMINAL);

        $container2 = new Container();
        $container2->setContainerNumber('TEST456');
        $container2->setStatus(ContainerStatus::IN_TRANSIT);

        $containers = [$container1, $container2];
        $user = new StaffUser();
        $newStatus = ContainerStatus::ALERT;

        // Expect dwell time service to be called for each container
        $this->dwellTimeService->expects($this->exactly(2))
            ->method('handleStatusChange');

        // Expect entity manager flush for each container
        $this->entityManager->expects($this->exactly(2))
            ->method('flush');

        $results = $this->service->batchChangeStatus($containers, $newStatus, $user);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]['success']);
        $this->assertTrue($results[1]['success']);
        $this->assertEquals('TEST123', $results[0]['container_number']);
        $this->assertEquals('TEST456', $results[1]['container_number']);
    }

    public function testIsValidStatusTransitionAlertToAny(): void
    {
        // ALERT can transition to any other status
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::ALERT, 
            ContainerStatus::AT_TERMINAL
        ));
        
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::ALERT, 
            ContainerStatus::RETURNED
        ));
        
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::ALERT, 
            ContainerStatus::AVAILABLE_FOR_RETURN
        ));
    }

    public function testIsValidStatusTransitionToAlert(): void
    {
        // Any status can transition to ALERT
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::AT_TERMINAL, 
            ContainerStatus::ALERT
        ));
        
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::AVAILABLE_FOR_RETURN, 
            ContainerStatus::ALERT
        ));
        
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::IN_TRANSIT, 
            ContainerStatus::ALERT
        ));
    }

    public function testIsValidStatusTransitionNormalFlow(): void
    {
        // Test normal workflow transitions
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::AVAILABLE_FOR_RETURN, 
            ContainerStatus::PA_APPROVED
        ));
        
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::PA_APPROVED, 
            ContainerStatus::IN_TRANSIT
        ));
        
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::IN_TRANSIT, 
            ContainerStatus::AT_TERMINAL
        ));
        
        $this->assertTrue($this->service->isValidStatusTransition(
            ContainerStatus::AT_TERMINAL, 
            ContainerStatus::RETURNED
        ));
    }

    public function testIsValidStatusTransitionInvalid(): void
    {
        // Test some invalid transitions
        $this->assertFalse($this->service->isValidStatusTransition(
            ContainerStatus::RETURNED, 
            ContainerStatus::IN_TRANSIT
        ));
        
        $this->assertFalse($this->service->isValidStatusTransition(
            ContainerStatus::IN_TRANSIT, 
            ContainerStatus::PA_APPROVED
        ));
    }
}