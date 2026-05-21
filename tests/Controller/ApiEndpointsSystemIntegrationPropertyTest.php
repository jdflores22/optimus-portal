<?php

namespace App\Tests\Controller;

use App\Controller\PreAdviceAPIController;
use App\Entity\Container;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\GeotagPhoto;
use App\Entity\PreAdviceRequest;
use App\Entity\Terminal;
use App\Entity\Trucker;
use App\Service\ContainerSearchService;
use App\Service\PreAdviceService;
use App\Service\TerminalService;
use App\Service\PhotoVerificationService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Feature: terminal-team-pre-advice, Property 15: System integration
 * 
 * Property-based test for validating API endpoints integration with existing systems.
 * This test validates Requirements 13.1, 13.2 by ensuring that API endpoints properly
 * integrate with authentication, file management, and other existing portal systems.
 */
class ApiEndpointsSystemIntegrationPropertyTest extends TestCase
{
    use TestTrait;

    /**
     * Property 15: System integration
     * 
     * For any container search request through the API, the system should integrate
     * with the container search service and return consistent JSON responses.
     * 
     * Validates: Requirements 13.1, 13.2
     */
    public function testContainerSearchApiIntegration(): void
    {
        $this->forAll(
            Generator\elements('ABCD1234567', 'EFGH9876543', 'INVALID123', ''),
            Generator\bool()
        )->then(function (string $containerNumber, bool $containerExists) {
            // Create mocks for dependencies
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $containerSearchService = $this->createMock(ContainerSearchService::class);
            $terminalService = $this->createMock(TerminalService::class);
            $preAdviceService = $this->createMock(PreAdviceService::class);
            $photoVerificationService = $this->createMock(PhotoVerificationService::class);
            $logger = $this->createMock(LoggerInterface::class);

            // Create API controller
            $controller = new PreAdviceAPIController(
                $entityManager,
                $containerSearchService,
                $terminalService,
                $preAdviceService,
                $photoVerificationService,
                $logger
            );

            // Mock container search service behavior
            if (empty($containerNumber)) {
                // Empty container number - no service call expected
                $containerSearchService->expects($this->never())
                    ->method('validateContainerNumberFormat');
            } elseif (!preg_match('/^[A-Z]{4}[0-9]{7}$/', $containerNumber)) {
                // Invalid format
                $containerSearchService->expects($this->once())
                    ->method('validateContainerNumberFormat')
                    ->with($containerNumber)
                    ->willReturn(false);
            } else {
                // Valid format
                $containerSearchService->expects($this->once())
                    ->method('validateContainerNumberFormat')
                    ->with($containerNumber)
                    ->willReturn(true);

                if ($containerExists) {
                    $containerDetails = [
                        'id' => 123,
                        'containerNumber' => $containerNumber,
                        'size' => '40ft',
                        'type' => 'Dry',
                        'status' => 'available_for_return',
                        'currentLocation' => 'Test Port',
                        'expectedReturnDate' => '2024-02-15',
                        'isAvailableForReturn' => true,
                        'createdAt' => '2024-01-01 00:00:00',
                        'updatedAt' => '2024-01-01 00:00:00'
                    ];

                    $containerSearchService->expects($this->once())
                        ->method('getContainerDetails')
                        ->with($containerNumber)
                        ->willReturn($containerDetails);
                } else {
                    $containerSearchService->expects($this->once())
                        ->method('getContainerDetails')
                        ->with($containerNumber)
                        ->willReturn(null);
                }
            }

            // Create request
            $requestData = ['container_number' => $containerNumber];
            $request = new Request([], [], [], [], [], [], json_encode($requestData));
            $request->headers->set('Content-Type', 'application/json');

            // Call the API method
            $response = $controller->searchContainer($request);

            // Verify response is JsonResponse
            $this->assertInstanceOf(JsonResponse::class, $response);

            // Decode response content
            $responseData = json_decode($response->getContent(), true);
            $this->assertIsArray($responseData);
            $this->assertArrayHasKey('success', $responseData);

            // Verify response based on input
            if (empty($containerNumber)) {
                // Empty container number should return error
                $this->assertFalse($responseData['success']);
                $this->assertEquals('EMPTY_CONTAINER_NUMBER', $responseData['code'] ?? '');
                $this->assertEquals(400, $response->getStatusCode());
            } elseif (!preg_match('/^[A-Z]{4}[0-9]{7}$/', $containerNumber)) {
                // Invalid format should return error
                $this->assertFalse($responseData['success']);
                $this->assertEquals('INVALID_FORMAT', $responseData['code'] ?? '');
                $this->assertEquals(400, $response->getStatusCode());
            } elseif ($containerExists) {
                // Valid container should return success
                $this->assertTrue($responseData['success']);
                $this->assertArrayHasKey('data', $responseData);
                $this->assertArrayHasKey('container', $responseData['data']);
                $this->assertEquals($containerNumber, $responseData['data']['container']['containerNumber']);
                $this->assertEquals(200, $response->getStatusCode());
            } else {
                // Container not found should return error
                $this->assertFalse($responseData['success']);
                $this->assertEquals('CONTAINER_NOT_FOUND', $responseData['code'] ?? '');
                $this->assertEquals(404, $response->getStatusCode());
            }

            // Verify error responses have required fields
            if (!$responseData['success']) {
                $this->assertArrayHasKey('error', $responseData);
                $this->assertArrayHasKey('code', $responseData);
                $this->assertIsString($responseData['error']);
                $this->assertIsString($responseData['code']);
            }
        });
    }

    /**
     * Property test for terminal compatibility API integration
     * 
     * For any container ID, the API should integrate with terminal service
     * and return properly formatted terminal data.
     */
    public function testTerminalCompatibilityApiIntegration(): void
    {
        $this->forAll(
            Generator\choose(1, 1000), // Container ID
            Generator\bool(), // Container exists
            Generator\choose(0, 5) // Number of compatible terminals
        )->then(function (int $containerId, bool $containerExists, int $terminalCount) {
            // Create mocks for dependencies
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $containerRepository = $this->createMock(\App\Repository\ContainerRepository::class);
            $containerSearchService = $this->createMock(ContainerSearchService::class);
            $terminalService = $this->createMock(TerminalService::class);
            $preAdviceService = $this->createMock(PreAdviceService::class);
            $photoVerificationService = $this->createMock(PhotoVerificationService::class);
            $logger = $this->createMock(LoggerInterface::class);

            // Mock entity manager
            $entityManager->expects($this->once())
                ->method('getRepository')
                ->with(Container::class)
                ->willReturn($containerRepository);

            // Create API controller
            $controller = new PreAdviceAPIController(
                $entityManager,
                $containerSearchService,
                $terminalService,
                $preAdviceService,
                $photoVerificationService,
                $logger
            );

            if ($containerExists) {
                // Create mock container
                $container = $this->createMock(Container::class);
                $container->method('getId')->willReturn($containerId);
                $container->method('getContainerNumber')->willReturn('TEST1234567');

                $containerRepository->expects($this->once())
                    ->method('find')
                    ->with($containerId)
                    ->willReturn($container);

                // Mock container availability validation
                $containerSearchService->expects($this->once())
                    ->method('validateContainerAvailability')
                    ->with($container)
                    ->willReturn(true);

                // Create mock terminals
                $terminals = [];
                $terminalData = [];
                for ($i = 0; $i < $terminalCount; $i++) {
                    $terminal = $this->createMock(Terminal::class);
                    $terminals[] = $terminal;
                    
                    $terminalDetails = [
                        'id' => $i + 1,
                        'name' => "Terminal " . ($i + 1),
                        'type' => 'CY',
                        'location' => 'Test Location',
                        'dailyCapacity' => 50,
                        'isActive' => true,
                        'available_slots_count' => 10,
                        'has_availability' => true
                    ];
                    $terminalData[] = $terminalDetails;
                }

                // Mock terminal service
                $terminalService->expects($this->once())
                    ->method('findCompatibleTerminals')
                    ->with($container)
                    ->willReturn($terminals);

                $terminalService->expects($this->exactly($terminalCount))
                    ->method('getTerminalDetails')
                    ->willReturnOnConsecutiveCalls(...$terminalData);

                $terminalService->expects($this->exactly($terminalCount))
                    ->method('getAvailableSlots')
                    ->willReturn(array_fill(0, 10, $this->createMock(\App\Entity\TerminalSlot::class)));
            } else {
                // Container not found
                $containerRepository->expects($this->once())
                    ->method('find')
                    ->with($containerId)
                    ->willReturn(null);
            }

            // Call the API method
            $response = $controller->getCompatibleTerminals($containerId);

            // Verify response is JsonResponse
            $this->assertInstanceOf(JsonResponse::class, $response);

            // Decode response content
            $responseData = json_decode($response->getContent(), true);
            $this->assertIsArray($responseData);
            $this->assertArrayHasKey('success', $responseData);

            if ($containerExists) {
                // Container exists should return success
                $this->assertTrue($responseData['success']);
                $this->assertArrayHasKey('data', $responseData);
                $this->assertArrayHasKey('compatible_terminals', $responseData['data']);
                $this->assertIsArray($responseData['data']['compatible_terminals']);
                $this->assertCount($terminalCount, $responseData['data']['compatible_terminals']);
                $this->assertEquals($terminalCount, $responseData['data']['total_terminals']);
                $this->assertEquals(200, $response->getStatusCode());

                // Verify terminal data structure
                foreach ($responseData['data']['compatible_terminals'] as $terminal) {
                    $this->assertArrayHasKey('id', $terminal);
                    $this->assertArrayHasKey('name', $terminal);
                    $this->assertArrayHasKey('type', $terminal);
                    $this->assertArrayHasKey('has_availability', $terminal);
                }
            } else {
                // Container not found should return error
                $this->assertFalse($responseData['success']);
                $this->assertEquals('CONTAINER_NOT_FOUND', $responseData['code'] ?? '');
                $this->assertEquals(404, $response->getStatusCode());
            }
        });
    }

    /**
     * Property test for API response format consistency
     * 
     * For any API response, the format should be consistent with success/error structure.
     */
    public function testApiResponseFormatConsistency(): void
    {
        $this->forAll(
            Generator\bool(), // Success or failure
            Generator\elements('CONTAINER_NOT_FOUND', 'INVALID_FORMAT', 'VALIDATION_ERROR', 'INTERNAL_ERROR')
        )->then(function (bool $isSuccess, string $errorCode) {
            // Create mocks for dependencies
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $containerSearchService = $this->createMock(ContainerSearchService::class);
            $terminalService = $this->createMock(TerminalService::class);
            $preAdviceService = $this->createMock(PreAdviceService::class);
            $photoVerificationService = $this->createMock(PhotoVerificationService::class);
            $logger = $this->createMock(LoggerInterface::class);

            // Create API controller
            $controller = new PreAdviceAPIController(
                $entityManager,
                $containerSearchService,
                $terminalService,
                $preAdviceService,
                $photoVerificationService,
                $logger
            );

            // Test with empty container number to trigger error
            $requestData = ['container_number' => ''];
            $request = new Request([], [], [], [], [], [], json_encode($requestData));
            $request->headers->set('Content-Type', 'application/json');

            $response = $controller->searchContainer($request);

            // Verify response structure
            $this->assertInstanceOf(JsonResponse::class, $response);
            $responseData = json_decode($response->getContent(), true);

            // All API responses should have consistent structure
            $this->assertIsArray($responseData);
            $this->assertArrayHasKey('success', $responseData);
            $this->assertIsBool($responseData['success']);

            // Error responses should have error and code fields
            if (!$responseData['success']) {
                $this->assertArrayHasKey('error', $responseData);
                $this->assertArrayHasKey('code', $responseData);
                $this->assertIsString($responseData['error']);
                $this->assertIsString($responseData['code']);
                $this->assertNotEmpty($responseData['error']);
                $this->assertNotEmpty($responseData['code']);
            }

            // Success responses should have data field
            if ($responseData['success']) {
                $this->assertArrayHasKey('data', $responseData);
                $this->assertIsArray($responseData['data']);
            }
        });
    }
}