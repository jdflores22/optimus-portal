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
use App\Controller\TerminalTeamController;
use App\Controller\TruckerController;
use App\Service\ContainerSearchService;
use App\Service\PreAdviceService;
use App\Service\TerminalService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * User Interface Validation Test
 * 
 * This test validates that Terminal Team and Trucker interfaces are working correctly,
 * verifies pre-advice submission and verification workflows, and tests payment integration
 * and EDO generation functionality.
 */
class UserInterfaceValidationTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private TerminalTeamController $terminalTeamController;
    private TruckerController $truckerController;

    protected function setUp(): void
    {
        // Don't boot kernel here for WebTestCase
    }

    private function getEntityManager(): EntityManagerInterface
    {
        if (!$this->entityManager) {
            self::bootKernel();
            $container = static::getContainer();
            $this->entityManager = $container->get(EntityManagerInterface::class);
        }
        return $this->entityManager;
    }

    /**
     * Test Terminal Team Dashboard Interface
     */
    public function testTerminalTeamDashboardInterface(): void
    {
        $client = static::createClient();
        
        // Create a terminal team user for testing
        $terminalTeamUser = $this->createTerminalTeamUser();
        
        // Login as terminal team user
        $client->loginUser($terminalTeamUser);
        
        // Test dashboard access
        $crawler = $client->request('GET', '/terminal-team/');
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.dashboard-metrics');
        $this->assertSelectorTextContains('h1', 'Terminal Team Dashboard');
        
        // Verify dashboard metrics are displayed
        $this->assertSelectorExists('.metric-card');
        $this->assertSelectorExists('[data-metric="pending_requests"]');
        $this->assertSelectorExists('[data-metric="verified_requests"]');
        $this->assertSelectorExists('[data-metric="available_slots"]');
        
        // Test pre-advice requests page
        $crawler = $client->request('GET', '/terminal-team/pre-advice-requests');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.pre-advice-requests-table');
    }

    /**
     * Test Trucker Dashboard Interface
     */
    public function testTruckerDashboardInterface(): void
    {
        $client = static::createClient();
        
        // Create a trucker user for testing
        $trucker = $this->createTrucker();
        
        // Login as trucker
        $client->loginUser($trucker);
        
        // Test dashboard access
        $crawler = $client->request('GET', '/trucker/');
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.trucker-dashboard');
        $this->assertSelectorTextContains('h1', 'Trucker Dashboard');
        
        // Verify dashboard statistics are displayed
        $this->assertSelectorExists('.stats-card');
        $this->assertSelectorExists('[data-stat="total_requests"]');
        $this->assertSelectorExists('[data-stat="pending_requests"]');
        
        // Test container search page
        $crawler = $client->request('GET', '/trucker/container-search');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#container-search-form');
        $this->assertSelectorExists('input[name="container_number"]');
    }

    /**
     * Test Container Search Workflow
     */
    public function testContainerSearchWorkflow(): void
    {
        $client = static::createClient();
        $trucker = $this->createTrucker();
        $client->loginUser($trucker);
        
        // Create test container
        $container = $this->createTestContainer();
        
        // Test container search API
        $client->request('POST', '/trucker/container-search/api', [
            'container_number' => $container->getContainerNumber()
        ]);
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('container', $response);
        $this->assertArrayHasKey('compatible_terminals', $response);
        $this->assertEquals($container->getContainerNumber(), $response['container']['containerNumber']);
    }

    /**
     * Test Pre-Advice Submission Workflow
     */
    public function testPreAdviceSubmissionWorkflow(): void
    {
        $client = static::createClient();
        $trucker = $this->createTrucker();
        $client->loginUser($trucker);
        
        // Create test data
        $container = $this->createTestContainer();
        $terminal = $this->createTestTerminal();
        
        // Test pre-advice creation page
        $crawler = $client->request('GET', '/trucker/pre-advice/create', [
            'container_number' => $container->getContainerNumber(),
            'terminal_id' => $terminal->getId()
        ]);
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#pre-advice-form');
        $this->assertSelectorExists('input[name="container_number"]');
        $this->assertSelectorExists('input[name="terminal_id"]');
        $this->assertSelectorExists('input[name="payment_reference"]');
        
        // Test form submission (without actual file upload for simplicity)
        $client->request('POST', '/trucker/pre-advice/submit', [
            'container_number' => $container->getContainerNumber(),
            'terminal_id' => $terminal->getId(),
            'payment_reference' => 'TEST_PAY_' . time()
        ]);
        
        // Should redirect or show validation errors
        $this->assertResponseStatusCodeSame(302);
    }

    /**
     * Test Pre-Advice Verification Workflow
     */
    public function testPreAdviceVerificationWorkflow(): void
    {
        $client = static::createClient();
        $terminalTeamUser = $this->createTerminalTeamUser();
        $client->loginUser($terminalTeamUser);
        
        // Create test pre-advice request
        $preAdvice = $this->createTestPreAdviceRequest();
        
        // Test pre-advice detail page
        $crawler = $client->request('GET', '/terminal-team/pre-advice/' . $preAdvice->getId());
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.pre-advice-detail');
        $this->assertSelectorExists('.verification-form');
        $this->assertSelectorExists('select[name="slot_id"]');
        $this->assertSelectorExists('textarea[name="verification_notes"]');
        
        // Test verification action
        $slot = $this->createTestSlot($preAdvice->getSelectedTerminal());
        
        $client->request('POST', '/terminal-team/pre-advice/' . $preAdvice->getId() . '/verify', [
            'slot_id' => $slot->getId(),
            'verification_notes' => 'Test verification notes'
        ]);
        
        $this->assertResponseStatusCodeSame(302);
    }

    /**
     * Test Payment Integration Interface
     */
    public function testPaymentIntegrationInterface(): void
    {
        $client = static::createClient();
        $trucker = $this->createTrucker();
        $client->loginUser($trucker);
        
        $container = $this->createTestContainer();
        $terminal = $this->createTestTerminal();
        
        // Test payment page
        $crawler = $client->request('GET', '/trucker/payment', [
            'container_number' => $container->getContainerNumber(),
            'terminal_id' => $terminal->getId()
        ]);
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#payment-form');
        $this->assertSelectorExists('select[name="payment_method"]');
        $this->assertSelectorExists('input[name="amount"]');
        
        // Test payment processing
        $client->request('POST', '/trucker/payment/process', [
            'payment_method' => 'credit_card',
            'amount' => '50.00',
            'container_number' => $container->getContainerNumber(),
            'terminal_id' => $terminal->getId(),
            'card_number' => '4111111111111111',
            'card_name' => 'Test User',
            'expiry_month' => '12',
            'expiry_year' => '2025',
            'cvv' => '123'
        ]);
        
        $this->assertResponseStatusCodeSame(302);
    }

    /**
     * Test EDO Generation Interface
     */
    public function testEDOGenerationInterface(): void
    {
        $client = static::createClient();
        $terminalTeamUser = $this->createTerminalTeamUser();
        $client->loginUser($terminalTeamUser);
        
        // Create verified pre-advice request with EDO
        $preAdvice = $this->createVerifiedPreAdviceRequest();
        
        // Test EDO download
        $client->request('GET', '/terminal-team/pre-advice/' . $preAdvice->getId() . '/edo');
        
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        
        // Test QR code download
        $client->request('GET', '/terminal-team/pre-advice/' . $preAdvice->getId() . '/qr-code');
        
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');
        
        // Test print package download
        $client->request('GET', '/terminal-team/pre-advice/' . $preAdvice->getId() . '/print-package');
        
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    /**
     * Test Navigation and Access Control
     */
    public function testNavigationAndAccessControl(): void
    {
        $client = static::createClient();
        
        // Test unauthorized access
        $client->request('GET', '/terminal-team/');
        $this->assertResponseStatusCodeSame(302); // Should redirect to login
        
        $client->request('GET', '/trucker/');
        $this->assertResponseStatusCodeSame(302); // Should redirect to login
        
        // Test Terminal Team access
        $terminalTeamUser = $this->createTerminalTeamUser();
        $client->loginUser($terminalTeamUser);
        
        $client->request('GET', '/terminal-team/');
        $this->assertResponseIsSuccessful();
        
        // Terminal Team should not access trucker routes
        $client->request('GET', '/trucker/');
        $this->assertResponseStatusCodeSame(403);
        
        // Test Trucker access
        $client = static::createClient();
        $trucker = $this->createTrucker();
        $client->loginUser($trucker);
        
        $client->request('GET', '/trucker/');
        $this->assertResponseIsSuccessful();
        
        // Trucker should not access terminal team routes
        $client->request('GET', '/terminal-team/');
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Test Form Validation and Error Handling
     */
    public function testFormValidationAndErrorHandling(): void
    {
        $client = static::createClient();
        $trucker = $this->createTrucker();
        $client->loginUser($trucker);
        
        // Test invalid container search
        $client->request('POST', '/trucker/container-search/api', [
            'container_number' => 'INVALID'
        ]);
        
        $this->assertResponseStatusCodeSame(400);
        $response = json_decode($client->getResponse()->getContent(), true);
        $this->assertFalse($response['success']);
        $this->assertArrayHasKey('error', $response);
        
        // Test empty pre-advice submission
        $client->request('POST', '/trucker/pre-advice/submit', []);
        
        $this->assertResponseStatusCodeSame(302);
        // Should redirect back with error flash message
    }

    // Helper methods for creating test data

    private function createTerminalTeamUser(): TerminalTeamUser
    {
        $user = new TerminalTeamUser();
        $user->setEmail('terminalteam@test.com');
        $user->setPassword('$2y$13$test.hash');
        $user->setRoles([UserRole::TERMINAL_TEAM->value]);
        $user->setFirstName('Terminal');
        $user->setLastName('Team');
        $user->setIsActive(true);
        
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
        
        return $user;
    }

    private function createTrucker(): Trucker
    {
        $trucker = new Trucker();
        $trucker->setEmail('trucker@test.com');
        $trucker->setPassword('$2y$13$test.hash');
        $trucker->setRoles([UserRole::TRUCKER->value]);
        $trucker->setFirstName('Test');
        $trucker->setLastName('Trucker');
        $trucker->setCompanyName('Test Trucking Co.');
        $trucker->setLicenseNumber('TL' . time());
        $trucker->setPhoneNumber('+1234567890');
        $trucker->setIsActive(true);
        
        $this->getEntityManager()->persist($trucker);
        $this->getEntityManager()->flush();
        
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
        
        $this->getEntityManager()->persist($container);
        $this->getEntityManager()->flush();
        
        return $container;
    }

    private function createTestTerminal(): Terminal
    {
        $terminal = new Terminal();
        $terminal->setName('Test Terminal');
        $terminal->setType(TerminalType::CY);
        $terminal->setLocation('Test Location');
        $terminal->setDailyCapacity(100);
        $terminal->setIsActive(true);
        
        $this->getEntityManager()->persist($terminal);
        $this->getEntityManager()->flush();
        
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
        
        $this->getEntityManager()->persist($preAdvice);
        $this->getEntityManager()->flush();
        
        return $preAdvice;
    }

    private function createVerifiedPreAdviceRequest(): PreAdviceRequest
    {
        $preAdvice = $this->createTestPreAdviceRequest();
        $preAdvice->setStatus(PreAdviceStatus::VERIFIED);
        $preAdvice->setEdoNumber('EDO' . date('YmdHis') . str_pad($preAdvice->getId(), 8, '0', STR_PAD_LEFT) . 'CY');
        $preAdvice->setQrCode('TTPA_v1_' . date('ymdHis') . str_pad($preAdvice->getId(), 6, '0', STR_PAD_LEFT) . 'CY' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)) . '_' . substr(hash('sha256', 'test_content'), 0, 8));
        $preAdvice->setVerifiedAt(new \DateTime());
        
        $this->getEntityManager()->flush();
        
        return $preAdvice;
    }

    private function createTestSlot(Terminal $terminal): \App\Entity\TerminalSlot
    {
        $slot = new \App\Entity\TerminalSlot();
        $slot->setTerminal($terminal);
        $slot->setDate(new \DateTime('+1 day'));
        $slot->setCapacity(20);
        $slot->setAssignedCount(0);
        $slot->setStatus(\App\Entity\Enum\SlotStatus::AVAILABLE);
        
        $this->getEntityManager()->persist($slot);
        $this->getEntityManager()->flush();
        
        return $slot;
    }
}