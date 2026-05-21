<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\DwellTimeEvent;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\DwellTimeEventType;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Service\ContainerStatusService;
use App\Service\DwellTimeServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ContainerAlertStatusIntegrationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ContainerStatusService $containerStatusService;
    private DwellTimeServiceInterface $dwellTimeService;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->entityManager = $kernel->getContainer()->get('doctrine')->getManager();
        $this->containerStatusService = $kernel->getContainer()->get(ContainerStatusService::class);
        $this->dwellTimeService = $kernel->getContainer()->get(DwellTimeServiceInterface::class);
    }

    public function testCompleteAlertStatusWorkflow(): void
    {
        // Create test user
        $user = new User();
        $user->setEmail('test@example.com');
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);
        $user->setPassword('hashed_password');
        $user->setIsVerified(true);
        $this->entityManager->persist($user);

        // Create test container
        $container = new Container();
        $container->setContainerNumber('ALERT001');
        $container->setSize('40');
        $container->setType('HC');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setTerminalArrivalDate(new \DateTime('-45 days'));
        $container->setCurrentDwellTime(45);
        $this->entityManager->persist($container);

        $this->entityManager->flush();

        // Test 1: Change status to ALERT (should pause dwell time)
        $this->containerStatusService->changeStatus(
            $container, 
            ContainerStatus::ALERT, 
            $user, 
            'Investigation required'
        );

        $this->entityManager->refresh($container);

        // Verify container status changed
        $this->assertEquals(ContainerStatus::ALERT, $container->getStatus());
        
        // Verify dwell time was paused
        $this->assertNotNull($container->getDwellTimePausedAt());
        
        // Verify audit event was created
        $events = $this->entityManager->getRepository(DwellTimeEvent::class)
            ->findBy(['container' => $container], ['eventDate' => 'DESC']);
        
        $this->assertGreaterThan(0, count($events));
        
        // Find the status change event
        $statusChangeEvent = null;
        $pauseEvent = null;
        
        foreach ($events as $event) {
            if ($event->getEventType() === DwellTimeEventType::STATUS_CHANGE) {
                $statusChangeEvent = $event;
            }
            if ($event->getEventType() === DwellTimeEventType::PAUSE) {
                $pauseEvent = $event;
            }
        }
        
        $this->assertNotNull($statusChangeEvent);
        $this->assertNotNull($pauseEvent);
        $this->assertEquals($user, $statusChangeEvent->getTriggeredBy());
        $this->assertEquals($user, $pauseEvent->getTriggeredBy());

        // Test 2: Change status back to AT_TERMINAL (should resume dwell time)
        $pausedAt = $container->getDwellTimePausedAt();
        
        // Wait a moment to ensure time difference
        sleep(1);
        
        $this->containerStatusService->changeStatus(
            $container, 
            ContainerStatus::AT_TERMINAL, 
            $user, 
            'Investigation completed'
        );

        $this->entityManager->refresh($container);

        // Verify container status changed back
        $this->assertEquals(ContainerStatus::AT_TERMINAL, $container->getStatus());
        
        // Verify dwell time was resumed (paused_at should be null)
        $this->assertNull($container->getDwellTimePausedAt());
        
        // Verify total paused days was updated
        $this->assertGreaterThan(0, $container->getTotalPausedDays());

        // Verify resume event was created
        $events = $this->entityManager->getRepository(DwellTimeEvent::class)
            ->findBy(['container' => $container], ['eventDate' => 'DESC']);
        
        $resumeEvent = null;
        foreach ($events as $event) {
            if ($event->getEventType() === DwellTimeEventType::RESUME) {
                $resumeEvent = $event;
                break;
            }
        }
        
        $this->assertNotNull($resumeEvent);
        $this->assertEquals($user, $resumeEvent->getTriggeredBy());
        $this->assertStringContains('pause', $resumeEvent->getReason());

        // Clean up
        $this->entityManager->remove($container);
        $this->entityManager->remove($user);
        $this->entityManager->flush();
    }

    public function testAlertStatusPreventsNotifications(): void
    {
        // Create test container with high dwell time
        $container = new Container();
        $container->setContainerNumber('ALERT002');
        $container->setSize('20');
        $container->setType('DV');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setTerminalArrivalDate(new \DateTime('-65 days')); // Over 60 days
        $container->setCurrentDwellTime(65);
        $this->entityManager->persist($container);

        $this->entityManager->flush();

        // Check notifications when not in alert status
        $notifications = $this->dwellTimeService->checkNotificationThresholds($container);
        $this->assertNotEmpty($notifications); // Should have notifications

        // Change to ALERT status
        $container->setStatus(ContainerStatus::ALERT);
        $this->dwellTimeService->pauseDwellTime($container, 'Testing alert status');

        // Check notifications when in alert status
        $notifications = $this->dwellTimeService->checkNotificationThresholds($container);
        $this->assertEmpty($notifications); // Should not have notifications

        // Clean up
        $this->entityManager->remove($container);
        $this->entityManager->flush();
    }

    public function testAlertStatusPreventsAutomaticReturn(): void
    {
        // Create test container with very high dwell time
        $container = new Container();
        $container->setContainerNumber('ALERT003');
        $container->setSize('40');
        $container->setType('DV');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setExpectedReturnDate(new \DateTime('+30 days'));
        $container->setTerminalArrivalDate(new \DateTime('-95 days')); // Over 90 days
        $container->setCurrentDwellTime(95);
        $this->entityManager->persist($container);

        $this->entityManager->flush();

        // Change to ALERT status
        $container->setStatus(ContainerStatus::ALERT);
        $this->dwellTimeService->pauseDwellTime($container, 'Testing alert status');

        $originalStatus = $container->getStatus();

        // Try automatic return processing
        $this->dwellTimeService->processAutomaticReturn($container);

        // Status should remain ALERT (not changed to RETURNED)
        $this->assertEquals($originalStatus, $container->getStatus());
        $this->assertEquals(ContainerStatus::ALERT, $container->getStatus());

        // Clean up
        $this->entityManager->remove($container);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}