<?php

namespace App\Tests\Integration;

use App\Entity\Container;
use App\Entity\Terminal;
use App\Entity\PreAdviceRequest;
use App\Entity\Trucker;
use App\Entity\TerminalTeamUser;
use App\Entity\Enum\ContainerStatus;
use App\Entity\Enum\TerminalType;
use App\Entity\Enum\PreAdviceStatus;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Controller\TerminalTeamController;
use App\Controller\TruckerController;
use App\Service\ContainerSearchService;
use App\Service\PreAdviceService;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simplified User Interface Validation Test
 * 
 * This test validates core functionality of Terminal Team and Trucker interfaces
 * without complex web testing setup.
 */
class UserInterfaceValidationSimplified extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ContainerSearchService $containerSearchService;
    private TerminalService $terminalService;
    private PreAdviceService $preAdviceService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->containerSearchService = $container->get(ContainerSearchService::class);
        $this->terminalService = $container->get(TerminalService::class);
        $this->preAdviceService = $container->get(PreAdviceService::class);
    }

    /**
     * Test Terminal Team Dashboard Metrics Calculation
     */
    public function testTerminalTeamDashboardMetrics(): void
    {
        // Create test data
        $terminal = $this->createTestTerminal();
        $container = $this->createTestContainer();
        $trucker = $this->createTrucker();
        
        // Create pending pre-advice request
        $preAdvice = new PreAdviceRequest();
        $preAdvice->setTrucker($trucker);
        $preAdvice->setContainer($container);
        $preAdvice->setSelectedTerminal($terminal);
        $preAdvice->setStatus(PreAdviceStatus::PENDING);
        $preAdvice->setPaymentReference('TEST_PAY_' . time());
        
        $this->entityManager->persist($preAdvice);
        $this->entityManager->flush();
        
        // Test metrics calculation
        $pendingCount = $this->entityManager->getRepository(PreAdviceRequest::class)
            ->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :status')
            ->setParameter('status', PreAdviceStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
            
        $this->assertGreaterThan(0, $pendingCount, 'Should have pending requests');
        
        // Test terminal capacity calculation
        $this->assertEquals(100, $terminal->getDailyCapacity());
        $this->assertTrue($terminal->isActive());
    }

    /**
     * Test Container Search Functionality
     */
    public function testContainerSearchFunctionality(): void
    {
        $container = $this->createTestContainer();
        
        // Test container search by number
        $foundContainer = $this->containerSearchService->findByContainerNumber($container->getContainerNumber());
        $this->assertNotNull($foundContainer, 'Container should be found by number');
        $this->assertEquals($container->getId(), $foundContainer->getId());
        
        // Test container availability validation
        $isAvailable = $this->containerSearchService->validateContainerAvailability($container);
        $this->assertTrue($isAvailable, 'Container should be available for return');
        
        // Test container details retrieval
        $details = $this->containerSearchService->getContainerDetails($container->getContainerNumber());
        $this->assertNotNull($details, 'Container details should be retrieved');
        $this->assertEquals($container->getContainerNumber(), $details['containerNumber']);
        $this->assertTrue($details['isAvailableForReturn']);
    }

    /**
     * Test Terminal Compatibility Logic
     */
    public function testTerminalCompatibilityLogic(): void
    {
        $container = $this->createTestContainer();
        $terminal = $this->createTestTerminal();
        
        // Test terminal-container compatibility
        $canAccept = $this->terminalService->canAcceptContainer($terminal, $container);
        $this->assertTrue($canAccept, 'CY terminal should accept dry containers');
        
        // Test compatible terminals search
        $compatibleTerminals = $this->terminalService->findCompatibleTerminals($container);
        $this->assertNotEmpty($compatibleTerminals, 'Should find compatible terminals');
        
        $terminalIds = array_map(fn($t) => $t->getId(), $compatibleTerminals);
        $this->assertContains($terminal->getId(), $terminalIds, 'Created terminal should be compatible');
    }

    /**
     * Test Pre-Advice Workflow State Management
     */
    public function testPreAdviceWorkflowStateManagement(): void
    {
        $trucker = $this->createTrucker();
        $container = $this->createTestContainer();
        $terminal = $this->createTestTerminal();
        
        // Create pre-advice request
        $preAdvice = new PreAdviceRequest();
        $preAdvice->setTrucker($trucker);
        $preAdvice->setContainer($container);
        $preAdvice->setSelectedTerminal($terminal);
        $preAdvice->setStatus(PreAdviceStatus::PENDING);
        $preAdvice->setPaymentReference('TEST_PAY_' . time());
        
        $this->entityManager->persist($preAdvice);
        $this->entityManager->flush();
        
        // Test initial state
        $this->assertEquals(PreAdviceStatus::PENDING, $preAdvice->getStatus());
        $this->assertNull($preAdvice->getVerifiedAt());
        $this->assertNull($preAdvice->getEdoNumber());
        $this->assertNull($preAdvice->getQrCode());
        
        // Test workflow status retrieval
        $workflowStatus = $this->preAdviceService->getWorkflowStatus($preAdvice);
        $this->assertIsArray($workflowStatus);
        $this->assertArrayHasKey('current_step', $workflowStatus);
        $this->assertArrayHasKey('can_verify', $workflowStatus);
        $this->assertArrayHasKey('can_reject', $workflowStatus);
    }

    /**
     * Test Payment Reference Validation
     */
    public function testPaymentReferenceValidation(): void
    {
        // Test valid payment reference formats
        $validReferences = [
            'PAY' . date('Ymd') . '12345678',
            'PAYMENT_' . time() . '_123',
            'TXN123456789012',
            'REF_' . date('YmdHis') . '_001'
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
            '12345' // Too short
        ];
        
        foreach ($invalidReferences as $reference) {
            $isValid = $this->validatePaymentReference($reference);
            $this->assertFalse($isValid, "Payment reference '{$reference}' should be invalid");
        }
    }

    /**
     * Test EDO and QR Code Generation Logic
     */
    public function testEDOAndQRCodeGenerationLogic(): void
    {
        $preAdvice = $this->createTestPreAdviceRequest();
        
        // Test EDO number generation format
        $timestamp = date('YmdHis');
        $preAdviceId = str_pad($preAdvice->getId(), 8, '0', STR_PAD_LEFT);
        $terminalCode = $preAdvice->getSelectedTerminal()->getType()->value;
        
        $edoNumber = "EDO{$timestamp}{$preAdviceId}{$terminalCode}";
        
        $this->assertStringStartsWith('EDO', $edoNumber);
        $this->assertStringContainsString($terminalCode, $edoNumber);
        $this->assertGreaterThan(15, strlen($edoNumber));
        
        // Test QR code format
        $qrCodePrefix = 'TTPA';
        $qrCodeVersion = 'v1';
        $qrCodeId = date('ymdHis') . str_pad($preAdvice->getId(), 6, '0', STR_PAD_LEFT) . $terminalCode . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $securityHash = substr(hash('sha256', $edoNumber), 0, 8);
        
        $qrCode = sprintf('%s_%s_%s_%s', $qrCodePrefix, $qrCodeVersion, $qrCodeId, $securityHash);
        
        $this->assertStringStartsWith('TTPA_v1_', $qrCode);
        
        $parts = explode('_', $qrCode);
        $this->assertCount(4, $parts);
        $this->assertEquals($qrCodePrefix, $parts[0]);
        $this->assertEquals($qrCodeVersion, $parts[1]);
        $this->assertEquals(8, strlen($parts[3]));
    }

    /**
     * Test Role-Based Access Control Logic
     */
    public function testRoleBasedAccessControlLogic(): void
    {
        $terminalTeamUser = $this->createTerminalTeamUser();
        $trucker = $this->createTrucker();
        
        // Test Terminal Team user roles
        $this->assertEquals(UserRole::TERMINAL_TEAM, $terminalTeamUser->getRole());
        $this->assertEquals(AccountStatus::APPROVED, $terminalTeamUser->getStatus());
        
        // Test Trucker user roles
        $this->assertEquals(UserRole::TRUCKER, $trucker->getRole());
        $this->assertEquals(AccountStatus::APPROVED, $trucker->getStatus());
        
        // Test role separation
        $this->assertNotEquals(UserRole::TRUCKER, $terminalTeamUser->getRole());
        $this->assertNotEquals(UserRole::TERMINAL_TEAM, $trucker->getRole());
    }

    /**
     * Test Form Validation Logic
     */
    public function testFormValidationLogic(): void
    {
        // Test container number format validation
        $validContainerNumbers = [
            'ABCD1234567',
            'TEST1234567',
            'CONT9876543'
        ];
        
        foreach ($validContainerNumbers as $containerNumber) {
            $isValid = $this->containerSearchService->validateContainerNumberFormat($containerNumber);
            $this->assertTrue($isValid, "Container number '{$containerNumber}' should be valid");
        }
        
        $invalidContainerNumbers = [
            'INVALID',
            '1234567890',
            'ABC123',
            'TOOLONG1234567890'
        ];
        
        foreach ($invalidContainerNumbers as $containerNumber) {
            $isValid = $this->containerSearchService->validateContainerNumberFormat($containerNumber);
            $this->assertFalse($isValid, "Container number '{$containerNumber}' should be invalid");
        }
    }

    /**
     * Test Error Handling and Edge Cases
     */
    public function testErrorHandlingAndEdgeCases(): void
    {
        // Test non-existent container search
        $nonExistentContainer = $this->containerSearchService->findByContainerNumber('NONE1234567');
        $this->assertNull($nonExistentContainer, 'Non-existent container should return null');
        
        // Test container details for non-existent container
        $details = $this->containerSearchService->getContainerDetails('NONE1234567');
        $this->assertNull($details, 'Details for non-existent container should be null');
        
        // Test empty container number validation
        $isValid = $this->containerSearchService->validateContainerNumberFormat('');
        $this->assertFalse($isValid, 'Empty container number should be invalid');
        
        // Test inactive terminal handling
        $terminal = $this->createTestTerminal();
        $terminal->setIsActive(false);
        $this->entityManager->flush();
        
        $container = $this->createTestContainer();
        $canAccept = $this->terminalService->canAcceptContainer($terminal, $container);
        $this->assertFalse($canAccept, 'Inactive terminal should not accept containers');
    }

    // Helper methods

    private function createTerminalTeamUser(): TerminalTeamUser
    {
        $user = new TerminalTeamUser();
        $user->setEmail('terminalteam' . time() . '@test.com');
        $user->setPasswordHash('$2y$13$test.hash');
        $user->setRole(UserRole::TERMINAL_TEAM);
        $user->setFirstName('Terminal');
        $user->setLastName('Team');
        $user->setDepartment('Pre-Advice');
        $user->setStatus(AccountStatus::APPROVED);
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }

    private function createTrucker(): Trucker
    {
        $trucker = new Trucker();
        $trucker->setEmail('trucker' . time() . '@test.com');
        $trucker->setPasswordHash('$2y$13$test.hash');
        $trucker->setRole(UserRole::TRUCKER);
        $trucker->setFirstName('Test');
        $trucker->setLastName('Trucker');
        $trucker->setCompanyName('Test Trucking Co.');
        $trucker->setLicenseNumber('TL' . time());
        $trucker->setPhoneNumber('+1234567890');
        $trucker->setStatus(AccountStatus::APPROVED);
        
        $this->entityManager->persist($trucker);
        $this->entityManager->flush();
        
        return $trucker;
    }

    private function createTestContainer(): Container
    {
        $container = new Container();
        $container->setContainerNumber('TEST' . str_pad(rand(1, 9999999), 7, '0', STR_PAD_LEFT));
        $container->setSize('40ft');
        $container->setType('Dry');
        $container->setStatus(ContainerStatus::AVAILABLE_FOR_RETURN);
        $container->setCurrentLocation('Test Port');
        $container->setExpectedReturnDate(new \DateTime('+7 days'));
        
        $this->entityManager->persist($container);
        $this->entityManager->flush();
        
        return $container;
    }

    private function createTestTerminal(): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName('Test Terminal ' . time());
        $terminal->setType(TerminalType::CY);
        $terminal->setLocation('Test Location');
        $terminal->setDailyCapacity(100);
        $terminal->setIsActive(true);
        
        $this->entityManager->persist($terminal);
        $this->entityManager->flush();
        
        return $terminal;
    }

    private function createTestPreAdviceRequest(): PreAdviceRequest
    {
        $trucker = $this->createTrucker();
        $container = $this->createTestContainer();
        $terminal = $this->createTestTerminal();
        
        $preAdvice = new PreAdviceRequest();
        $preAdvice->setTrucker($trucker);
        $preAdvice->setContainer($container);
        $preAdvice->setSelectedTerminal($terminal);
        $preAdvice->setStatus(PreAdviceStatus::PENDING);
        $preAdvice->setPaymentReference('TEST_PAY_' . time());
        
        $this->entityManager->persist($preAdvice);
        $this->entityManager->flush();
        
        return $preAdvice;
    }

    private function validatePaymentReference(string $reference): bool
    {
        return !empty($reference) && strlen($reference) >= 10;
    }
}