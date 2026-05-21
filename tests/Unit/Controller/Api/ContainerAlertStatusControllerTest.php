<?php

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\ContainerAlertStatusController;
use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\UserRole;
use App\Entity\StaffUser;
use App\Service\DwellTimeServiceInterface;
use App\Service\JwtService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ContainerAlertStatusControllerTest extends TestCase
{
    private ContainerAlertStatusController $controller;
    private MockObject|JwtService $jwtService;
    private MockObject|UserService $userService;
    private MockObject|EntityManagerInterface $entityManager;
    private MockObject|DwellTimeServiceInterface $dwellTimeService;
    private MockObject|ValidatorInterface $validator;
    private MockObject|EntityRepository $containerRepository;

    protected function setUp(): void
    {
        $this->jwtService = $this->createMock(JwtService::class);
        $this->userService = $this->createMock(UserService::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->dwellTimeService = $this->createMock(DwellTimeServiceInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->containerRepository = $this->createMock(EntityRepository::class);

        $this->entityManager->method('getRepository')
            ->with(Container::class)
            ->willReturn($this->containerRepository);

        $this->controller = new ContainerAlertStatusController(
            $this->jwtService,
            $this->userService,
            $this->entityManager,
            $this->dwellTimeService,
            $this->validator
        );
    }

    public function testPauseAlertStatusSuccess(): void
    {
        // Create test data
        $user = new StaffUser();
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $container = new Container();
        $container->setContainerNumber('TEST123');
        $container->setStatus(ContainerStatus::AT_TERMINAL);
        $container->setCurrentDwellTime(45);

        // Mock authentication
        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        // Mock container repository
        $this->containerRepository->method('findOneBy')
            ->with(['containerNumber' => 'TEST123'])
            ->willReturn($container);

        // Create request
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer valid-token'],
            json_encode(['reason' => 'Investigation required'])
        );

        // Execute
        $response = $this->controller->pauseAlertStatus('TEST123', $request);

        // Assert
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Container alert status activated and dwell time paused', $data['message']);
        $this->assertEquals('TEST123', $data['data']['container_number']);
        $this->assertEquals(ContainerStatus::ALERT->value, $data['data']['new_status']);
    }

    public function testPauseAlertStatusUnauthorized(): void
    {
        // Mock failed authentication
        $this->jwtService->method('getUserIdFromToken')->willReturn(null);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer invalid-token'],
            json_encode(['reason' => 'Investigation required'])
        );

        $response = $this->controller->pauseAlertStatus('TEST123', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(401, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Authentication required', $data['error']);
    }

    public function testPauseAlertStatusInsufficientPermissions(): void
    {
        // Create user with insufficient permissions
        $user = new StaffUser();
        $user->setRole(UserRole::CONSIGNEE);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer valid-token'],
            json_encode(['reason' => 'Investigation required'])
        );

        $response = $this->controller->pauseAlertStatus('TEST123', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(403, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Insufficient permissions', $data['error']);
    }

    public function testPauseAlertStatusContainerNotFound(): void
    {
        $user = new StaffUser();
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);
        $this->containerRepository->method('findOneBy')->willReturn(null);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer valid-token'],
            json_encode(['reason' => 'Investigation required'])
        );

        $response = $this->controller->pauseAlertStatus('TEST123', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(404, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Container not found', $data['error']);
    }

    public function testPauseAlertStatusMissingReason(): void
    {
        $user = new StaffUser();
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $container = new Container();
        $container->setContainerNumber('TEST123');
        $container->setStatus(ContainerStatus::AT_TERMINAL);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);
        $this->containerRepository->method('findOneBy')->willReturn($container);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer valid-token'],
            json_encode([]) // Missing reason
        );

        $response = $this->controller->pauseAlertStatus('TEST123', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Reason is required', $data['error']);
    }

    public function testResumeAlertStatusSuccess(): void
    {
        $user = new StaffUser();
        $user->setRole(UserRole::TERMINAL_TEAM);

        $container = new Container();
        $container->setContainerNumber('TEST123');
        $container->setStatus(ContainerStatus::ALERT);
        $container->setCurrentDwellTime(45);
        $container->setTotalPausedDays(5);

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);
        $this->containerRepository->method('findOneBy')->willReturn($container);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer valid-token'],
            json_encode(['target_status' => ContainerStatus::AT_TERMINAL->value])
        );

        $response = $this->controller->resumeAlertStatus('TEST123', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Container alert status deactivated and dwell time resumed', $data['message']);
        $this->assertEquals('TEST123', $data['data']['container_number']);
        $this->assertEquals(ContainerStatus::AT_TERMINAL->value, $data['data']['new_status']);
    }

    public function testResumeAlertStatusNotInAlert(): void
    {
        $user = new StaffUser();
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $container = new Container();
        $container->setContainerNumber('TEST123');
        $container->setStatus(ContainerStatus::AT_TERMINAL); // Not in ALERT status

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);
        $this->containerRepository->method('findOneBy')->willReturn($container);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer valid-token'],
            json_encode(['target_status' => ContainerStatus::AT_TERMINAL->value])
        );

        $response = $this->controller->resumeAlertStatus('TEST123', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(400, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Container is not in alert status', $data['error']);
    }

    public function testGetAlertStatusSuccess(): void
    {
        $user = new StaffUser();
        $user->setRole(UserRole::SHIPPING_LINES_ADMIN);

        $container = new Container();
        $container->setContainerNumber('TEST123');
        $container->setStatus(ContainerStatus::ALERT);
        $container->setCurrentDwellTime(45);
        $container->setTotalPausedDays(5);
        $container->setTerminalArrivalDate(new \DateTime('2024-01-01'));

        $this->jwtService->method('getUserIdFromToken')->willReturn(1);
        $this->userService->method('findById')->willReturn($user);
        $this->containerRepository->method('findOneBy')->willReturn($container);
        
        $this->dwellTimeService->method('getDwellTimeHistory')
            ->willReturn([
                [
                    'event_type' => 'pause',
                    'event_date' => new \DateTime(),
                    'dwell_time_at_event' => 45,
                    'reason' => 'Investigation required'
                ]
            ]);

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer valid-token']
        );

        $response = $this->controller->getAlertStatus('TEST123', $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('TEST123', $data['data']['container_number']);
        $this->assertTrue($data['data']['is_alert_active']);
        $this->assertEquals(45, $data['data']['current_dwell_time']);
        $this->assertEquals(5, $data['data']['total_paused_days']);
    }
}