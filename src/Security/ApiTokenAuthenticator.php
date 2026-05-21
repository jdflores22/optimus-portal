<?php

namespace App\Security;

use App\Entity\Trucker;
use App\Repository\TruckerRepository;
use App\Service\AuthenticationIntegrationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TruckerRepository $truckerRepository,
        private LoggerInterface $logger,
        private AuthenticationIntegrationService $authIntegrationService
    ) {
    }

    public function supports(Request $request): ?bool
    {
        // Only support API routes
        return str_starts_with($request->getPathInfo(), '/api/');
    }

    public function authenticate(Request $request): Passport
    {
        $apiToken = $this->extractApiToken($request);
        
        if (null === $apiToken) {
            throw new CustomUserMessageAuthenticationException('No API token provided');
        }

        // Validate token format
        if (!$this->isValidTokenFormat($apiToken)) {
            throw new CustomUserMessageAuthenticationException('Invalid API token format');
        }

        // Find user by API token using integration service
        $user = $this->authIntegrationService->findUserByApiToken($apiToken);
        
        if (!$user) {
            throw new CustomUserMessageAuthenticationException('Invalid API token');
        }

        // Log successful authentication
        $this->logger->info('API token authentication successful', [
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'token_prefix' => substr($apiToken, 0, 8) . '...'
        ]);

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier(), function () use ($user) {
                return $user;
            })
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // The integration service already handles last activity update
        // Return null to continue with the request
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $this->logger->warning('API authentication failed', [
            'error' => $exception->getMessage(),
            'request_path' => $request->getPathInfo(),
            'ip_address' => $request->getClientIp()
        ]);

        return new JsonResponse([
            'success' => false,
            'error' => 'Authentication failed',
            'code' => 'AUTHENTICATION_FAILED',
            'message' => $exception->getMessageKey()
        ], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Extract API token from request
     */
    private function extractApiToken(Request $request): ?string
    {
        // Try Authorization header first (Bearer token)
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Try X-API-Token header
        $apiTokenHeader = $request->headers->get('X-API-Token');
        if ($apiTokenHeader) {
            return $apiTokenHeader;
        }

        // Try query parameter (less secure, for development only)
        $queryToken = $request->query->get('api_token');
        if ($queryToken) {
            $this->logger->warning('API token provided via query parameter', [
                'request_path' => $request->getPathInfo(),
                'ip_address' => $request->getClientIp()
            ]);
            return $queryToken;
        }

        return null;
    }

    /**
     * Validate API token format
     */
    private function isValidTokenFormat(string $token): bool
    {
        // API tokens should be at least 32 characters long and contain only alphanumeric characters
        return strlen($token) >= 32 && preg_match('/^[a-zA-Z0-9]+$/', $token);
    }

    /**
     * Find user by API token (deprecated - use AuthenticationIntegrationService)
     */
    private function findUserByApiToken(string $apiToken): ?Trucker
    {
        // Hash the token to compare with stored hash
        $tokenHash = hash('sha256', $apiToken);
        
        return $this->truckerRepository->findOneBy(['apiTokenHash' => $tokenHash]);
    }

    /**
     * Check if API token is expired (deprecated - handled by AuthenticationIntegrationService)
     */
    private function isTokenExpired(Trucker $user, string $apiToken): bool
    {
        $tokenExpiresAt = $user->getApiTokenExpiresAt();
        
        if (!$tokenExpiresAt) {
            // No expiration set, token is valid
            return false;
        }

        return $tokenExpiresAt < new \DateTime();
    }
}