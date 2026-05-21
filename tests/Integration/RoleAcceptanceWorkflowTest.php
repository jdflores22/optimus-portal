<?php

namespace App\Tests\Integration;

use App\Entity\PendingUser;
use App\Entity\User;
use App\Entity\StaffUser;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\AccountStatus;
use App\Service\PendingUserService;
use App\Service\EmailNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class RoleAcceptanceWorkflowTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private PendingUserService $pendingUserService;

    protected function setUp(): void
    {
        // Don't boot kernel here, let each test method handle it
    }

    private function getEntityManager(): EntityManagerInterface
    {
        $kernel = self::bootKernel();
        return $kernel->getContainer()->get('doctrine')->getManager();
    }

    private function getPendingUserService(): PendingUserService
    {
        $kernel = self::getContainer();
        return $kernel->get(PendingUserService::class);
    }

    public function testRoleAcceptancePageDisplaysCorrectly(): void
    {
        $this->entityManager = $this->getEntityManager();
        $this->pendingUserService = $this->getPendingUserService();
        
        // Create a test admin user
        $admin = $this->createTestAdmin();
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        // Create a pending user
        $pendingUser = $this->pendingUserService->createPendingUser(
            'test@example.com',
            'John',
            'Doe',
            UserRole::SL_STAFF,
            $admin
        );

        $client = static::createClient();
        
        // Access the role acceptance page
        $crawler = $client->request('GET', '/role-acceptance/' . $pendingUser->getAcceptanceToken());

        // Verify the page loads successfully
        $this->assertResponseIsSuccessful();
        
        // Verify the page contains expected content
        $this->assertSelectorTextContains('h2', 'OPTIMUS Portal');
        $this->assertSelectorExists('form[action*="/accept"]');
        $this->assertSelectorExists('form[action*="/decline"]');
        $this->assertSelectorTextContains('.bg-blue-50', 'John Doe');
        $this->assertSelectorTextContains('.bg-blue-50', 'test@example.com');
        $this->assertSelectorTextContains('.bg-blue-50', 'Sl Staff');

        // Clean up
        $this->entityManager->remove($pendingUser);
        $this->entityManager->remove($admin);
        $this->entityManager->flush();
    }

    public function testInvalidTokenShowsErrorPage(): void
    {
        $client = static::createClient();
        
        // Access the role acceptance page with invalid token
        $crawler = $client->request('GET', '/role-acceptance/invalid_token_123');

        // Verify the page loads successfully but shows error
        $this->assertResponseIsSuccessful();
        
        // Verify error content is displayed
        $this->assertSelectorTextContains('h2', 'Invalid Link');
        $this->assertSelectorTextContains('.bg-red-50', 'invalid');
    }

    public function testExpiredTokenShowsErrorPage(): void
    {
        $this->entityManager = $this->getEntityManager();
        
        // Create a test admin user
        $admin = $this->createTestAdmin();
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        // Create a pending user with expired token
        $pendingUser = new PendingUser();
        $pendingUser->setEmail('expired@example.com');
        $pendingUser->setFirstName('Jane');
        $pendingUser->setLastName('Smith');
        $pendingUser->setRole(UserRole::SL_STAFF);
        $pendingUser->setCreatedByAdmin($admin);
        $pendingUser->generateAcceptanceToken();
        
        // Set expiration to past date
        $pendingUser->setTokenExpiresAt(new \DateTime('-1 day'));
        
        $this->entityManager->persist($pendingUser);
        $this->entityManager->flush();

        $client = static::createClient();
        
        // Access the role acceptance page with expired token
        $crawler = $client->request('GET', '/role-acceptance/' . $pendingUser->getAcceptanceToken());

        // Verify the page loads successfully but shows error
        $this->assertResponseIsSuccessful();
        
        // Verify error content is displayed
        $this->assertSelectorTextContains('h2', 'Invitation Expired');
        $this->assertSelectorTextContains('.bg-red-50', 'expired');

        // Clean up
        $this->entityManager->remove($pendingUser);
        $this->entityManager->remove($admin);
        $this->entityManager->flush();
    }

    public function testRoleAcceptanceCreatesUserAccount(): void
    {
        $this->entityManager = $this->getEntityManager();
        $this->pendingUserService = $this->getPendingUserService();
        
        // Create a test admin user
        $admin = $this->createTestAdmin();
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        // Create a pending user
        $pendingUser = $this->pendingUserService->createPendingUser(
            'accept@example.com',
            'Alice',
            'Johnson',
            UserRole::SL_STAFF,
            $admin
        );

        $client = static::createClient();
        
        // First, get the acceptance page to get CSRF token
        $crawler = $client->request('GET', '/role-acceptance/' . $pendingUser->getAcceptanceToken());
        $this->assertResponseIsSuccessful();

        // Extract CSRF token from the form
        $form = $crawler->selectButton('Accept Role')->form();
        $csrfToken = $form['_token']->getValue();

        // Submit the acceptance form
        $client->request('POST', '/role-acceptance/' . $pendingUser->getAcceptanceToken() . '/accept', [
            '_token' => $csrfToken
        ]);

        // Verify successful acceptance
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Role Accepted!');

        // Verify user account was created
        $userRepository = $this->entityManager->getRepository(User::class);
        $newUser = $userRepository->findOneBy(['email' => 'accept@example.com']);
        
        $this->assertNotNull($newUser);
        $this->assertEquals(UserRole::SL_STAFF, $newUser->getRole());
        $this->assertEquals(AccountStatus::APPROVED, $newUser->getStatus());
        
        // Verify department is set correctly for StaffUser
        $this->assertInstanceOf(StaffUser::class, $newUser);
        $this->assertEquals('Operations', $newUser->getDepartment());
        $this->assertEquals('Alice', $newUser->getFirstName());
        $this->assertEquals('Johnson', $newUser->getLastName());

        // Verify pending user status was updated to accepted (not removed immediately)
        $this->entityManager->refresh($pendingUser);
        $this->assertEquals('accepted', $pendingUser->getStatus());

        // Clean up
        $this->entityManager->remove($pendingUser);
        $this->entityManager->remove($newUser);
        $this->entityManager->remove($admin);
        $this->entityManager->flush();
    }

    public function testRoleDeclineRemovesPendingUser(): void
    {
        $this->entityManager = $this->getEntityManager();
        $this->pendingUserService = $this->getPendingUserService();
        
        // Create a test admin user
        $admin = $this->createTestAdmin();
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        // Create a pending user
        $pendingUser = $this->pendingUserService->createPendingUser(
            'decline@example.com',
            'Bob',
            'Wilson',
            UserRole::SL_STAFF,
            $admin
        );

        $client = static::createClient();
        
        // First, get the acceptance page to get CSRF token
        $crawler = $client->request('GET', '/role-acceptance/' . $pendingUser->getAcceptanceToken());
        $this->assertResponseIsSuccessful();

        // Extract CSRF token from the decline form
        $form = $crawler->selectButton('Decline Role')->form();
        $csrfToken = $form['_token']->getValue();

        // Submit the decline form
        $client->request('POST', '/role-acceptance/' . $pendingUser->getAcceptanceToken() . '/decline', [
            '_token' => $csrfToken
        ]);

        // Verify successful decline
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h2', 'Role Declined');

        // Verify no user account was created
        $userRepository = $this->entityManager->getRepository(User::class);
        $newUser = $userRepository->findOneBy(['email' => 'decline@example.com']);
        $this->assertNull($newUser);

        // Verify pending user status was updated
        $this->entityManager->refresh($pendingUser);
        $this->assertEquals('declined', $pendingUser->getStatus());

        // Clean up
        $this->entityManager->remove($pendingUser);
        $this->entityManager->remove($admin);
        $this->entityManager->flush();
    }

    private function createTestAdmin(): User
    {
        $admin = new StaffUser();
        $admin->setEmail('admin@example.com');
        $admin->setRole(UserRole::SYSTEM_ADMIN);
        $admin->setStatus(AccountStatus::APPROVED);
        $admin->setPasswordHash('$2y$13$test_hash');
        $admin->setFirstName('Admin');
        $admin->setLastName('User');
        $admin->setDepartment('Administration');
        
        return $admin;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up any remaining test data
        $this->entityManager->close();
        $this->entityManager = null;
    }
}