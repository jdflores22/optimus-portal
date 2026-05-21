<?php

namespace App\Tests\Unit;

use App\Controller\RoleAcceptanceController;
use App\Entity\PendingUser;
use App\Entity\User;
use App\Entity\Enum\UserRole;
use App\Service\PendingUserService;
use App\Service\EmailNotificationService;
use App\Service\InAppNotificationService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Twig\Environment;

class RoleAcceptanceControllerTest extends TestCase
{
    private RoleAcceptanceController $controller;
    private MockObject $pendingUserService;
    private MockObject $emailNotificationService;
    private MockObject $inAppNotificationService;
    private MockObject $csrfTokenManager;
    private MockObject $logger;
    private MockObject $container;
    private MockObject $twig;

    protected function setUp(): void
    {
        $this->pendingUserService = $this->createMock(PendingUserService::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);
        $this->inAppNotificationService = $this->createMock(InAppNotificationService::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->twig = $this->createMock(Environment::class);

        $this->controller = new RoleAcceptanceController(
            $this->pendingUserService,
            $this->emailNotificationService,
            $this->inAppNotificationService,
            $this->csrfTokenManager,
            $this->logger
        );

        // Set up container for the controller
        $this->controller->setContainer($this->container);
    }

    public function testShowAcceptancePageWithValidToken(): void
    {
        $token = 'valid_token_123';
        $pendingUser = $this->createMockPendingUser();
        
        $this->pendingUserService
            ->expects($this->once())
            ->method('findByToken')
            ->with($token)
            ->willReturn($pendingUser);

        $pendingUser
            ->expects($this->once())
            ->method('canBeProcessed')
            ->willReturn(true);

        $csrfToken = $this->createMock(CsrfToken::class);
        $csrfToken->method('getValue')->willReturn('csrf_token_value');

        $this->csrfTokenManager
            ->expects($this->once())
            ->method('getToken')
            ->with('role_acceptance_' . $token)
            ->willReturn($csrfToken);

        $this->container
            ->expects($this->once())
            ->method('get')
            ->with('twig')
            ->willReturn($this->twig);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('role_acceptance/show.html.twig', $this->isType('array'))
            ->willReturn('<html>Role acceptance page</html>');

        $response = $this->controller->showAcceptancePage($token);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Role acceptance page', $response->getContent());
    }

    public function testShowAcceptancePageWithInvalidToken(): void
    {
        $token = 'invalid_token';
        
        $this->pendingUserService
            ->expects($this->once())
            ->method('findByToken')
            ->with($token)
            ->willReturn(null);

        $this->container
            ->expects($this->once())
            ->method('get')
            ->with('twig')
            ->willReturn($this->twig);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('role_acceptance/error.html.twig', $this->callback(function ($data) {
                return $data['error_type'] === 'invalid_token';
            }))
            ->willReturn('<html>Invalid token error</html>');

        $response = $this->controller->showAcceptancePage($token);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Invalid token error', $response->getContent());
    }

    public function testShowAcceptancePageWithExpiredToken(): void
    {
        $token = 'expired_token';
        $pendingUser = $this->createMockPendingUser();
        
        $this->pendingUserService
            ->expects($this->once())
            ->method('findByToken')
            ->with($token)
            ->willReturn($pendingUser);

        $pendingUser
            ->expects($this->once())
            ->method('canBeProcessed')
            ->willReturn(false);

        $pendingUser
            ->expects($this->once())
            ->method('isExpired')
            ->willReturn(true);

        $this->container
            ->expects($this->once())
            ->method('get')
            ->with('twig')
            ->willReturn($this->twig);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('role_acceptance/error.html.twig', $this->callback(function ($data) {
                return $data['error_type'] === 'expired_token';
            }))
            ->willReturn('<html>Expired token error</html>');

        $response = $this->controller->showAcceptancePage($token);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Expired token error', $response->getContent());
    }

    public function testAcceptRoleWithValidRequest(): void
    {
        $token = 'valid_token_123';
        $pendingUser = $this->createMockPendingUser();
        $newUser = $this->createMockUser();
        
        $request = new Request();
        $request->request->set('_token', 'valid_csrf_token');
        
        // Mock rate limiter
        $rateLimiter = $this->createMock(\Symfony\Component\RateLimiter\RateLimiterInterface::class);
        $limit = $this->createMock(\Symfony\Component\RateLimiter\Limit::class);
        $limit->method('isAccepted')->willReturn(true);
        $rateLimiter->method('consume')->willReturn($limit);
        
        $this->container
            ->expects($this->once())
            ->method('get')
            ->with('limiter.role_acceptance')
            ->willReturn($rateLimiter);

        // Mock CSRF token validation
        $csrfToken = new CsrfToken('role_acceptance_' . $token, 'valid_csrf_token');
        $this->csrfTokenManager
            ->expects($this->once())
            ->method('isTokenValid')
            ->with($this->equalTo($csrfToken))
            ->willReturn(true);

        $this->pendingUserService
            ->expects($this->once())
            ->method('findByToken')
            ->with($token)
            ->willReturn($pendingUser);

        $pendingUser
            ->expects($this->once())
            ->method('canBeProcessed')
            ->willReturn(true);

        $this->pendingUserService
            ->expects($this->once())
            ->method('acceptRole')
            ->with($pendingUser)
            ->willReturn($newUser);

        $this->emailNotificationService
            ->expects($this->once())
            ->method('sendWelcomeEmail')
            ->with($newUser);

        $this->container
            ->expects($this->exactly(2))
            ->method('get')
            ->willReturnMap([
                ['limiter.role_acceptance', $rateLimiter],
                ['twig', $this->twig]
            ]);

        $this->twig
            ->expects($this->once())
            ->method('render')
            ->with('role_acceptance/success.html.twig', $this->isType('array'))
            ->willReturn('<html>Success page</html>');

        $response = $this->controller->acceptRole($token, $request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Success page', $response->getContent());
    }

    private function createMockPendingUser(): MockObject
    {
        $pendingUser = $this->createMock(PendingUser::class);
        $pendingUser->method('getEmail')->willReturn('test@example.com');
        $pendingUser->method('getRole')->willReturn(UserRole::SL_STAFF);
        $pendingUser->method('getTokenExpiresAt')->willReturn(new \DateTime('+1 day'));
        
        $admin = $this->createMock(User::class);
        $admin->method('getEmail')->willReturn('admin@example.com');
        $pendingUser->method('getCreatedByAdmin')->willReturn($admin);
        
        return $pendingUser;
    }

    private function createMockUser(): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);
        $user->method('getEmail')->willReturn('test@example.com');
        $user->method('getRole')->willReturn(UserRole::SL_STAFF);
        
        return $user;
    }
}