<?php

namespace App\Security;

use App\Entity\User;
use App\Service\ActivityLogService;
use App\Service\AuthenticationIntegrationService;
use App\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';
    public const MAX_FAILED_LOGIN_ATTEMPTS = 3;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private EntityManagerInterface $entityManager,
        private UserService $userService,
        private AuthenticationIntegrationService $authIntegrationService,
        private ActivityLogService $activityLogService
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->get('email', '');
        $password = $request->request->get('password', '');

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        // Check if user exists and validate account status
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        
        if ($user) {
            // Validate account status using integration service
            $validationErrors = $this->authIntegrationService->validateUserAccountStatus($user);
            
            if (!empty($validationErrors)) {
                throw new CustomUserMessageAuthenticationException(implode(' ', $validationErrors));
            }
        }

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($password),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Reset failed login attempts on successful authentication
        $user = $token->getUser();
        if ($user instanceof User) {
            $user->resetFailedLoginAttempts();
            $this->entityManager->flush();
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        // Use authentication integration service to determine redirect
        if ($user instanceof User) {
            $dashboardRoute = $this->authIntegrationService->getDashboardRouteForUser($user);
            return new RedirectResponse($this->urlGenerator->generate($dashboardRoute));
        }

        return new RedirectResponse($this->urlGenerator->generate('app_home'));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $email = (string) $request->request->get('email', '');
        $ipAddress = $request->getClientIp() ?? '127.0.0.1';
        $user = $email !== ''
            ? $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])
            : null;

        if (!$exception instanceof InvalidCsrfTokenException) {
            if ($user && !$user->isLocked()) {
                $user->incrementFailedLoginAttempts();

                if ($user->getFailedLoginAttempts() >= self::MAX_FAILED_LOGIN_ATTEMPTS) {
                    $this->userService->lockAccount($user->getId(), 30);
                } else {
                    $this->entityManager->flush();
                }
            }
        }

        $reason = $exception instanceof InvalidCsrfTokenException
            ? 'Invalid CSRF token'
            : $exception->getMessage();

        $this->activityLogService->logFailedLogin($email, $ipAddress, $user, $reason);

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
