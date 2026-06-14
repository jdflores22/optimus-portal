<?php

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class StructuredLogger
{
    public function __construct(
        private LoggerInterface $appLogger,
        private LoggerInterface $securityLogger,
        private LoggerInterface $auditLogger,
        private LoggerInterface $errorLogger,
        private LoggerInterface $notificationLogger,
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage
    ) {
    }

    public function logAppEvent(string $message, array $context = []): void
    {
        $context = $this->enrichContext($context);
        $this->appLogger->info($message, $context);
    }

    public function logSecurityEvent(string $message, array $context = []): void
    {
        $context = $this->enrichContext($context);
        $this->securityLogger->info($message, $context);
    }

    public function logAuditEvent(string $action, string $entityType, int $entityId, array $changes = [], ?User $user = null): void
    {
        $context = [
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'changes' => $changes,
            'user_id' => $user?->getId() ?? $this->getCurrentUserId(),
            'user_email' => $user?->getEmail() ?? $this->getCurrentUserEmail(),
            'timestamp' => (new \DateTime())->format('c'),
            'ip_address' => $this->requestStack->getCurrentRequest()?->getClientIp(),
            'user_agent' => $this->requestStack->getCurrentRequest()?->headers->get('User-Agent'),
            'request_uri' => $this->requestStack->getCurrentRequest()?->getRequestUri(),
        ];

        $this->auditLogger->info("Audit: {$action} on {$entityType}#{$entityId}", $context);
    }

    public function logError(string $message, ?\Throwable $exception = null, array $context = []): void
    {
        $context = $this->enrichContext($context);
        
        if ($exception) {
            $context['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        $this->errorLogger->error($message, $context);
    }

    public function logNotificationEvent(string $message, array $context = []): void
    {
        $context = $this->enrichContext($context);
        $this->notificationLogger->info($message, $context);
    }

    public function logUserAction(string $action, ?User $user = null, array $additionalContext = []): void
    {
        $user = $user ?? $this->getCurrentUser();
        
        $context = array_merge([
            'user_id' => $user?->getId(),
            'user_email' => $user?->getEmail(),
            'user_role' => $user?->getRole()?->value,
            'action' => $action,
            'timestamp' => (new \DateTime())->format('c'),
        ], $additionalContext);

        $context = $this->enrichContext($context);
        $this->appLogger->info("User action: {$action}", $context);
    }

    public function logLoginAttempt(string $email, bool $successful, ?string $reason = null): void
    {
        $context = [
            'email' => $email,
            'successful' => $successful,
            'reason' => $reason,
            'timestamp' => (new \DateTime())->format('c'),
        ];

        $context = $this->enrichContext($context);
        
        $message = $successful ? "Successful login for {$email}" : "Failed login attempt for {$email}";
        $this->securityLogger->info($message, $context);
    }

    public function logAccessAttempt(string $resource, bool $granted, ?User $user = null): void
    {
        $user = $user ?? $this->getCurrentUser();
        
        $context = [
            'resource' => $resource,
            'access_granted' => $granted,
            'user_id' => $user?->getId(),
            'user_email' => $user?->getEmail(),
            'user_role' => $user?->getRole()?->value,
            'timestamp' => (new \DateTime())->format('c'),
        ];

        $context = $this->enrichContext($context);
        
        $message = $granted ? "Access granted to {$resource}" : "Access denied to {$resource}";
        $this->securityLogger->info($message, $context);
    }

    private function enrichContext(array $context): array
    {
        $request = $this->requestStack->getCurrentRequest();
        
        return array_merge($context, [
            'session_id' => $request?->getSession()->getId(),
            'ip_address' => $request?->getClientIp(),
            'user_agent' => $request?->headers->get('User-Agent'),
            'request_method' => $request?->getMethod(),
            'request_uri' => $request?->getRequestUri(),
            'timestamp' => (new \DateTime())->format('c'),
        ]);
    }

    private function getCurrentUser(): ?User
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();
        
        return $user instanceof User ? $user : null;
    }

    private function getCurrentUserId(): ?int
    {
        return $this->getCurrentUser()?->getId();
    }

    private function getCurrentUserEmail(): ?string
    {
        return $this->getCurrentUser()?->getEmail();
    }
}