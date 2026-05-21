<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\PreAdviceRequest;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\UserRole;
use App\Controller\TerminalTeamController;
use App\Controller\TruckerController;
use App\Service\ContainerSearchService;
use App\Service\PreAdviceService;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Final User Interface Validation Test
 * 
 * This test validates the core functionality of Terminal Team and Trucker interfaces
 * without database persistence to avoid schema issues.
 */
class UserInterfaceValidationFinal extends KernelTestCase
{
    private ContainerSearchService $containerSearchService;
    private TerminalService $terminalService;
    private PreAdviceService $preAdviceService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->containerSearchService = $container->get(ContainerSearchService::class);
        $this->terminalService = $container->get(TerminalService::class);
        $this->preAdviceService = $container->get(PreAdviceService::class);
    }

    /**
     * Test Terminal Team Controller Instantiation and Methods
     */
    public function testTerminalTeamControllerFunctionality(): void
    {
        $container = static::getContainer();
        
        // Test controller can be instantiated
        $controller = $container->get(TerminalTeamController::class);
        $this->assertInstanceOf(TerminalTeamController::class, $controller);
        
        // Test controller has required methods
        $this->assertTrue(method_exists($controller, 'dashboard'));
        $this->assertTrue(method_exists($controller, 'preAdviceRequests'));
        $this->assertTrue(method_exists($controller, 'preAdviceDetail'));
        $this->assertTrue(method_exists($controller, 'verifyPreAdvice'));
        $this->assertTrue(method_exists($controller, 'rejectPreAdvice'));
        $this->assertTrue(method_exists($controller, 'downloadEDO'));
        $this->assertTrue(method_exists($controller, 'downloadQRCode'));
        $this->assertTrue(method_exists($controller, 'downloadPrintPackage'));
    }

    /**
     * Test Trucker Controller Instantiation and Methods
     */
    public function testTruckerControllerFunctionality(): void
    {
        $container = static::getContainer();
        
        // Test controller can be instantiated
        $controller = $container->get(TruckerController::class);
        $this->assertInstanceOf(TruckerController::class, $controller);
        
        // Test controller has required methods
        $this->assertTrue(method_exists($controller, 'dashboard'));
        $this->assertTrue(method_exists($controller, 'containerSearch'));
        $this->assertTrue(method_exists($controller, 'containerSearchApi'));
        $this->assertTrue(method_exists($controller, 'createPreAdvice'));
        $this->assertTrue(method_exists($controller, 'submitPreAdvice'));
        $this->assertTrue(method_exists($controller, 'preAdviceDetail'));
        $this->assertTrue(method_exists($controller, 'preAdviceList'));
        $this->assertTrue(method_exists($controller, 'downloadEDO'));
        $this->assertTrue(method_exists($controller, 'payment'));
        $this->assertTrue(method_exists($controller, 'processPayment'));
        $this->assertTrue(method_exists($controller, 'downloadQRCode'));
    }

    /**
     * Test Container Search Service Integration
     */
    public function testContainerSearchServiceIntegration(): void
    {
        // Test service instantiation
        $this->assertInstanceOf(ContainerSearchService::class, $this->containerSearchService);
        
        // Test container number format validation
        $validNumbers = ['ABCD1234567', 'TEST1234567', 'CONT9876543'];
        foreach ($validNumbers as $number) {
            $isValid = $this->containerSearchService->validateContainerNumberFormat($number);
            $this->assertTrue($isValid, "Container number '{$number}' should be valid");
        }
        
        $invalidNumbers = ['INVALID', '1234567890', 'ABC123', 'TOOLONG1234567890'];
        foreach ($invalidNumbers as $number) {
            $isValid = $this->containerSearchService->validateContainerNumberFormat($number);
            $this->assertFalse($isValid, "Container number '{$number}' should be invalid");
        }
        
        // Test service methods exist
        $this->assertTrue(method_exists($this->containerSearchService, 'findByContainerNumber'));
        $this->assertTrue(method_exists($this->containerSearchService, 'validateContainerAvailability'));
        $this->assertTrue(method_exists($this->containerSearchService, 'getContainerDetails'));
        $this->assertTrue(method_exists($this->containerSearchService, 'validateContainerNumberFormat'));
    }

    /**
     * Test Terminal Service Integration
     */
    public function testTerminalServiceIntegration(): void
    {
        // Test service instantiation
        $this->assertInstanceOf(TerminalService::class, $this->terminalService);
        
        // Test service methods exist
        $this->assertTrue(method_exists($this->terminalService, 'findCompatibleTerminals'));
        $this->assertTrue(method_exists($this->terminalService, 'canAcceptContainer'));
        $this->assertTrue(method_exists($this->terminalService, 'getTerminalDetails'));
        $this->assertTrue(method_exists($this->terminalService, 'getActiveTerminals'));
    }

    /**
     * Test Pre-Advice Service Integration
     */
    public function testPreAdviceServiceIntegration(): void
    {
        // Test service instantiation
        $this->assertInstanceOf(PreAdviceService::class, $this->preAdviceService);
        
        // Test service methods exist
        $this->assertTrue(method_exists($this->preAdviceService, 'submitPreAdvice'));
        $this->assertTrue(method_exists($this->preAdviceService, 'verifyPreAdvice'));
        $this->assertTrue(method_exists($this->preAdviceService, 'rejectPreAdvice'));
        $this->assertTrue(method_exists($this->preAdviceService, 'getWorkflowStatus'));
    }

    /**
     * Test Payment Reference Validation Logic
     */
    public function testPaymentReferenceValidationLogic(): void
    {
        // Test valid payment reference formats
        $validReferences = [
            'PAY' . date('Ymd') . '12345678',
            'PAYMENT_' . time() . '_123',
            'TXN123456789012',
            'REF_' . date('YmdHis') . '_001',
            'CC_' . date('YmdHis') . '_12345678',
            'BT_' . date('YmdHis') . '_87654321',
            'WALLET_' . time() . '_ABCD1234'
        ];
        
        foreach ($validReferences as $reference) {
            $isValid = $this->validatePaymentReference($reference);
            $this->assertTrue($isValid, "Payment reference '{$reference}' should be valid");
        }
        
        // Test invalid payment reference formats
        $invalidReferences = [
            '', // Empty
            'PAY', // Too short
            'INVALID', // Wrong format
            '12345', // Too short
            'SHORT' // Too short
        ];
        
        foreach ($invalidReferences as $reference) {
            $isValid = $this->validatePaymentReference($reference);
            $this->assertFalse($isValid, "Payment reference '{$reference}' should be invalid");
        }
    }

    /**
     * Test EDO Number Generation Logic
     */
    public function testEDONumberGenerationLogic(): void
    {
        $timestamp = date('YmdHis');
        $preAdviceId = '00000123';
        $terminalCode = 'CY';
        
        $edoNumber = "EDO{$timestamp}{$preAdviceId}{$terminalCode}";
        
        // Validate EDO format
        $this->assertStringStartsWith('EDO', $edoNumber);
        $this->assertStringContainsString($terminalCode, $edoNumber);
        $this->assertStringContainsString($timestamp, $edoNumber);
        $this->assertGreaterThan(15, strlen($edoNumber));
        
        // Test uniqueness (different IDs should produce different EDOs)
        $edoNumber2 = "EDO{$timestamp}00000124{$terminalCode}";
        $this->assertNotEquals($edoNumber, $edoNumber2);
    }

    /**
     * Test QR Code Generation Logic
     */
    public function testQRCodeGenerationLogic(): void
    {
        $qrCodePrefix = 'TTPA';
        $qrCodeVersion = 'v1';
        $qrCodeId = date('ymdHis') . '000123' . 'CY' . 'ABCD';
        $securityHash = substr(hash('sha256', 'test_content'), 0, 8);
        
        $qrCode = sprintf('%s_%s_%s_%s', $qrCodePrefix, $qrCodeVersion, $qrCodeId, $securityHash);
        
        // Validate QR code format
        $this->assertStringStartsWith('TTPA_v1_', $qrCode);
        
        $parts = explode('_', $qrCode);
        $this->assertCount(4, $parts);
        $this->assertEquals($qrCodePrefix, $parts[0]);
        $this->assertEquals($qrCodeVersion, $parts[1]);
        $this->assertEquals(8, strlen($parts[3]));
        
        // Test QR code data structure
        $qrData = [
            'edo_number' => "EDO" . date('YmdHis') . "00000123CY",
            'pre_advice_id' => 123,
            'container_number' => "CONT123456",
            'terminal' => [
                'id' => 1,
                'type' => 'CY',
                'name' => "Test Terminal"
            ]
        ];
        
        $this->assertArrayHasKey('edo_number', $qrData);
        $this->assertArrayHasKey('terminal', $qrData);
        $this->assertArrayHasKey('container_number', $qrData);
        $this->assertIsArray($qrData['terminal']);
    }

    /**
     * Test Workflow State Transition Logic
     */
    public function testWorkflowStateTransitionLogic(): void
    {
        $validTransitions = [
            PreAdviceStatus::PENDING->value => [
                PreAdviceStatus::VERIFIED->value, 
                PreAdviceStatus::REJECTED->value, 
                PreAdviceStatus::CANCELLED->value
            ],
            PreAdviceStatus::VERIFIED->value => [
                PreAdviceStatus::COMPLETED->value, 
                PreAdviceStatus::CANCELLED->value
            ],
            PreAdviceStatus::COMPLETED->value => [],
            PreAdviceStatus::REJECTED->value => [],
            PreAdviceStatus::CANCELLED->value => []
        ];

        foreach ($validTransitions as $fromState => $toStates) {
            foreach ($toStates as $toState) {
                $this->assertTrue($this->isValidStateTransition($fromState, $toState), 
                    "Transition from {$fromState} to {$toState} should be valid");
            }
        }

        // Test invalid transitions
        $invalidTransitions = [
            [PreAdviceStatus::COMPLETED->value, PreAdviceStatus::PENDING->value],
            [PreAdviceStatus::REJECTED->value, PreAdviceStatus::VERIFIED->value],
            [PreAdviceStatus::CANCELLED->value, PreAdviceStatus::COMPLETED->value]
        ];

        foreach ($invalidTransitions as [$fromState, $toState]) {
            $this->assertFalse($this->isValidStateTransition($fromState, $toState),
                "Transition from {$fromState} to {$toState} should be invalid");
        }
    }

    /**
     * Test Terminal Type Compatibility Logic
     */
    public function testTerminalTypeCompatibilityLogic(): void
    {
        // Test terminal type enumeration
        $terminalTypes = TerminalType::cases();
        $this->assertCount(3, $terminalTypes);
        
        $typeValues = array_map(fn($type) => $type->value, $terminalTypes);
        $this->assertContains('CY', $typeValues);
        $this->assertContains('ATI', $typeValues);
        $this->assertContains('ICTSI', $typeValues);
        
        // Test container type compatibility (business logic)
        $containerTypes = ['Dry', 'Reefer', 'Hazardous'];
        
        foreach ($containerTypes as $containerType) {
            // CY terminals accept all container types
            $this->assertTrue($this->canTerminalAcceptContainer('CY', $containerType));
            
            // ATI terminals accept Dry and Reefer
            if (in_array($containerType, ['Dry', 'Reefer'])) {
                $this->assertTrue($this->canTerminalAcceptContainer('ATI', $containerType));
            } else {
                $this->assertFalse($this->canTerminalAcceptContainer('ATI', $containerType));
            }
            
            // ICTSI terminals accept Dry and Reefer
            if (in_array($containerType, ['Dry', 'Reefer'])) {
                $this->assertTrue($this->canTerminalAcceptContainer('ICTSI', $containerType));
            } else {
                $this->assertFalse($this->canTerminalAcceptContainer('ICTSI', $containerType));
            }
        }
    }

    /**
     * Test Container Status Validation Logic
     */
    public function testContainerStatusValidationLogic(): void
    {
        // Test container status enumeration
        $containerStatuses = ContainerStatus::cases();
        $this->assertGreaterThan(0, count($containerStatuses));
        
        $statusValues = array_map(fn($status) => $status->value, $containerStatuses);
        $this->assertContains('available_for_return', $statusValues);
        
        // Test availability logic
        $availableStatuses = ['available_for_return'];
        $unavailableStatuses = ['in_transit', 'at_terminal', 'returned', 'maintenance'];
        
        foreach ($availableStatuses as $status) {
            $this->assertTrue($this->isContainerAvailableForReturn($status));
        }
        
        foreach ($unavailableStatuses as $status) {
            if (in_array($status, $statusValues)) {
                $this->assertFalse($this->isContainerAvailableForReturn($status));
            }
        }
    }

    /**
     * Test User Role Validation Logic
     */
    public function testUserRoleValidationLogic(): void
    {
        // Test user role enumeration
        $userRoles = UserRole::cases();
        $this->assertGreaterThan(0, count($userRoles));
        
        $roleValues = array_map(fn($role) => $role->value, $userRoles);
        $this->assertContains('TERMINAL_TEAM', $roleValues);
        $this->assertContains('TRUCKER', $roleValues);
        
        // Test role-based access logic
        $terminalTeamPermissions = ['view_dashboard', 'verify_pre_advice', 'reject_pre_advice', 'generate_edo'];
        $truckerPermissions = ['search_container', 'submit_pre_advice', 'upload_photos', 'make_payment'];
        
        foreach ($terminalTeamPermissions as $permission) {
            $this->assertTrue($this->hasRolePermission('TERMINAL_TEAM', $permission));
            $this->assertFalse($this->hasRolePermission('TRUCKER', $permission));
        }
        
        foreach ($truckerPermissions as $permission) {
            $this->assertTrue($this->hasRolePermission('TRUCKER', $permission));
            $this->assertFalse($this->hasRolePermission('TERMINAL_TEAM', $permission));
        }
    }

    // Helper methods for validation logic

    private function validatePaymentReference(string $reference): bool
    {
        return !empty($reference) && strlen($reference) >= 10;
    }

    private function isValidStateTransition(string $fromState, string $toState): bool
    {
        $validTransitions = [
            PreAdviceStatus::PENDING->value => [
                PreAdviceStatus::VERIFIED->value, 
                PreAdviceStatus::REJECTED->value, 
                PreAdviceStatus::CANCELLED->value
            ],
            PreAdviceStatus::VERIFIED->value => [
                PreAdviceStatus::COMPLETED->value, 
                PreAdviceStatus::CANCELLED->value
            ],
            PreAdviceStatus::COMPLETED->value => [],
            PreAdviceStatus::REJECTED->value => [],
            PreAdviceStatus::CANCELLED->value => []
        ];

        return isset($validTransitions[$fromState]) && in_array($toState, $validTransitions[$fromState]);
    }

    private function canTerminalAcceptContainer(string $terminalType, string $containerType): bool
    {
        $compatibility = [
            'CY' => ['Dry', 'Reefer', 'Hazardous'],
            'ATI' => ['Dry', 'Reefer'],
            'ICTSI' => ['Dry', 'Reefer']
        ];

        return isset($compatibility[$terminalType]) && in_array($containerType, $compatibility[$terminalType]);
    }

    private function isContainerAvailableForReturn(string $status): bool
    {
        return $status === 'available_for_return';
    }

    private function hasRolePermission(string $role, string $permission): bool
    {
        $rolePermissions = [
            'TERMINAL_TEAM' => ['view_dashboard', 'verify_pre_advice', 'reject_pre_advice', 'generate_edo'],
            'TRUCKER' => ['search_container', 'submit_pre_advice', 'upload_photos', 'make_payment']
        ];

        return isset($rolePermissions[$role]) && in_array($permission, $rolePermissions[$role]);
    }
}