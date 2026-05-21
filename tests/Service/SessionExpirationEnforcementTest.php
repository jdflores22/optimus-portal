<?php

namespace App\Tests\Service;

use App\Entity\Broker;
use App\Entity\Consignee;
use App\Entity\StaffUser;
use App\Entity\Enum\AccountStatus;
use App\Entity\Enum\UserRole;
use App\EventListener\SessionTimeoutListener;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Eris\Generator;
use Eris\TestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Feature: optimus-shipping-portal, Property 13: Session expiration enforcement
 * 
 * For any authenticated session, after the expiration time, 
 * any subsequent request should require re-authentication.
 * 
 * Validates: Requirements 9.3
 */
class SessionExpirationEnforcementTest extends KernelTestCase
{
    use TestTrait;

    private EntityManagerInterface $entityManager;
    private UserService $userService;
    private UserPasswordHasherInterface $passwordHasher;
    private TokenStorageInterface $tokenStorage;
    private RouterInterface $router;

    protected function setUp(): void
    {
        parent::setUp();
        
        self::bootKernel();
        $container = static::getContainer();
        
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->passwordHasher = $container->get(UserPasswordHasherInterface::class);
        $this->tokenStorage = $container->get(TokenStorageInterface::class);
        $this->router = $container->get(RouterInterface::class);
        $this->userService = new UserService($this->entityManager, $this->passwordHasher);
        
        // Configure Eris
        $this->minimumEvaluationRatio = 0.5;
        $this->iterations = 100;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up database
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $connection->executeStatement('TRUNCATE TABLE audit_logs');
        $connection->executeStatement('TRUNCATE TABLE users');
        $connection->executeStatement('TRUNCATE TABLE consignees');
        $connection->executeStatement('TRUNCATE TABLE brokers');
        $connection->executeStatement('TRUNCATE TABLE staff_users');
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $this->entityManager->close();
    }

    /**
     * Property: For any authenticated session, after expiration time, subsequent requests should require re-authentication
     */
    public function testSessionExpirationEnforcesReauthentication(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'timeElapsed' => $parts[0], // Time elapsed since last activity
                        'requestPath' => $parts[1],
                    ];
                },
                Generator\tuple(
                    Generator\choose(1801, 3600), // Between 30:01 minutes and 1 hour (expired)
                    Generator\elements('/dashboard', '/profile', '/shipments', '/accreditation')
                )
            )
        )->then(function ($testData) {
            // Create a mock user without database interaction
            $mockUser = $this->createMock(\App\Entity\User::class);
            $mockUser->method('getRoles')->willReturn(['ROLE_USER']);
            $mockUser->method('getUserIdentifier')->willReturn('test@example.com');
            
            // Create a mock session with last activity time
            $session = new Session(new MockArraySessionStorage());
            $session->start();
            
            // Set last activity to a time that would cause expiration
            $lastActivity = time() - $testData['timeElapsed'];
            $session->set('_last_activity', $lastActivity);
            
            // Create authentication token
            $token = new UsernamePasswordToken($mockUser, 'main', $mockUser->getRoles());
            $this->tokenStorage->setToken($token);
            
            // Create request with session
            $request = Request::create($testData['requestPath']);
            $request->setSession($session);
            
            // Create session timeout listener
            $listener = new SessionTimeoutListener($this->tokenStorage, $this->router);
            
            // Create request event
            $kernel = static::getContainer()->get('kernel');
            $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
            
            // Process the request through session timeout listener
            $listener->onKernelRequest($event);
            
            // Verify that session was invalidated due to timeout
            $this->assertNull($this->tokenStorage->getToken(), 
                'Authentication token should be cleared after session timeout');
            
            // If response was set, it should be a redirect to login
            $response = $event->getResponse();
            if ($response !== null) {
                $this->assertEquals(302, $response->getStatusCode(), 
                    'Response should be a redirect after session timeout');
                $this->assertStringContainsString('/login', $response->getTargetUrl(),
                    'Redirect should be to login page');
            }
        });
    }

    /**
     * Property: For any authenticated session within timeout period, requests should be allowed
     */
    public function testActiveSessionAllowsRequests(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'timeElapsed' => $parts[0], // Time elapsed since last activity (within timeout)
                        'requestPath' => $parts[1],
                    ];
                },
                Generator\tuple(
                    Generator\choose(1, 1799), // Between 1 second and 29:59 minutes (not expired)
                    Generator\elements('/dashboard', '/profile', '/shipments', '/accreditation')
                )
            )
        )->then(function ($testData) {
            // Create a mock user without database interaction
            $mockUser = $this->createMock(\App\Entity\User::class);
            $mockUser->method('getRoles')->willReturn(['ROLE_USER']);
            $mockUser->method('getUserIdentifier')->willReturn('test@example.com');
            
            // Create a mock session with recent last activity time
            $session = new Session(new MockArraySessionStorage());
            $session->start();
            
            // Set last activity to a recent time (within timeout)
            $lastActivity = time() - $testData['timeElapsed'];
            $session->set('_last_activity', $lastActivity);
            
            // Create authentication token
            $token = new UsernamePasswordToken($mockUser, 'main', $mockUser->getRoles());
            $this->tokenStorage->setToken($token);
            
            // Store original token for comparison
            $originalToken = $this->tokenStorage->getToken();
            
            // Create request with session
            $request = Request::create($testData['requestPath']);
            $request->setSession($session);
            
            // Create session timeout listener
            $listener = new SessionTimeoutListener($this->tokenStorage, $this->router);
            
            // Create request event
            $kernel = static::getContainer()->get('kernel');
            $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
            
            // Process the request through session timeout listener
            $listener->onKernelRequest($event);
            
            // Verify that session was NOT invalidated (still within timeout)
            $this->assertNotNull($this->tokenStorage->getToken(), 
                'Authentication token should remain when session is active');
            $this->assertEquals($originalToken->getUserIdentifier(), $this->tokenStorage->getToken()->getUserIdentifier(),
                'Same user should remain authenticated');
            
            // Verify session is still active
            $this->assertTrue($session->isStarted(), 'Session should remain active');
            
            // Verify last activity was updated
            $newLastActivity = $session->get('_last_activity');
            $this->assertGreaterThan($lastActivity, $newLastActivity,
                'Last activity time should be updated for active sessions');
            
            // No response should be set (request continues normally)
            $this->assertNull($event->getResponse(), 
                'No redirect response should be set for active sessions');
        });
    }

    /**
     * Property: Public routes should not be affected by session timeout
     */
    public function testPublicRoutesIgnoreSessionTimeout(): void
    {
        $this->forAll(
            Generator\map(
                function ($parts) {
                    return [
                        'publicPath' => $parts[0],
                        'timeElapsed' => $parts[1], // Any time elapsed
                    ];
                },
                Generator\tuple(
                    Generator\elements('/login', '/register', '/_profiler', '/assets/css/app.css'),
                    Generator\choose(1, 7200) // Any time from 1 second to 2 hours
                )
            )
        )->then(function ($testData) {
            // Create a mock session with any last activity time
            $session = new Session(new MockArraySessionStorage());
            $session->start();
            $session->set('_last_activity', time() - $testData['timeElapsed']);
            
            // No authentication token (public access)
            $this->tokenStorage->setToken(null);
            
            // Create request for public path
            $request = Request::create($testData['publicPath']);
            $request->setSession($session);
            
            // Create session timeout listener
            $listener = new SessionTimeoutListener($this->tokenStorage, $this->router);
            
            // Create request event
            $kernel = static::getContainer()->get('kernel');
            $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
            
            // Process the request through session timeout listener
            $listener->onKernelRequest($event);
            
            // Verify no authentication token was set (remains null)
            $this->assertNull($this->tokenStorage->getToken(), 
                'No authentication token should be set for public routes');
            
            // No response should be set (request continues normally)
            $this->assertNull($event->getResponse(), 
                'No redirect response should be set for public routes');
            
            // Session should remain as it was
            $this->assertTrue($session->isStarted(), 'Session should remain active for public routes');
        });
    }

    /**
     * Helper method to create a user for testing
     */
    private function createUserForTest(string $email, string $password, UserRole $role): \App\Entity\User
    {
        $data = [
            'email' => $email,
            'password' => $password,
        ];

        // Add required fields based on user type
        if ($role === UserRole::CONSIGNEE || $role === UserRole::BROKER) {
            $data['businessName'] = 'Test Business ' . uniqid();
        } else {
            $data['firstName'] = 'Test';
            $data['lastName'] = 'User';
            $data['department'] = 'Testing';
        }

        return $this->userService->createUser($data, $role);
    }
}