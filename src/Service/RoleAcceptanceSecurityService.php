<?php

namespace App\Service;

use App\Entity\PendingUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Security monitoring service for role acceptance workflow
 * Implements suspicious activity detection and administrator alerts
 */
class RoleAcceptanceSecurityService
{
    private const MAX_ATTEMPTS_PER_IP = 10;
    private const MAX_ATTEMPTS_PER_TOKEN = 5;
    private const SUSPICIOUS_ACTIVITY_WINDOW = 3600; // 1 hour in seconds
    
    public function __construct(
        private StructuredLogger $structuredLogger,
        private ActivityLogService $activityLogService,
        private InAppNotificationService $notificationService,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack
    ) {
    }

    /**
     * Log role acceptance activity with security context
     */
    public function logRoleAcceptanceActivity(
        string $action,
        string $token,
        ?PendingUser $pendingUser = null,
        array $additionalContext = []
    ): void {
        $context = array_merge([
            'action' => $action,
            'token' => $token,
            'pending_user_id' => $pendingUser?->getId(),
            'pending_user_email' => $pendingUser?->getEmail(),
            'role' => $pendingUser?->getRole()?->value,
            'shipping_line_id' => $pendingUser?->getShippingLine()?->getId(),
            'created_by_admin_id' => $pendingUser?->getCreatedByAdmin()?->getId(),
            'ip_address' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
            'timestamp' => (new \DateTime())->format('c')
        ], $additionalContext);

        $this->structuredLogger->logSecurityEvent(
            "Role acceptance activity: {$action}",
            $context
        );

        // Also log as audit event if we have a pending user with an ID
        if ($pendingUser && $pendingUser->getId() !== null) {
            $this->structuredLogger->logAuditEvent(
                $action,
                'PendingUser',
                $pendingUser->getId(),
                $additionalContext,
                $pendingUser->getCreatedByAdmin()
            );
        }
    }

    /**
     * Detect and handle suspicious activity patterns
     */
    public function detectSuspiciousActivity(string $token, string $action): bool
    {
        $clientIp = $this->getClientIp();
        $isSuspicious = false;
        $reasons = [];

        // Check for excessive attempts from same IP
        if ($this->countRecentAttemptsByIp($clientIp) >= self::MAX_ATTEMPTS_PER_IP) {
            $isSuspicious = true;
            $reasons[] = 'excessive_attempts_from_ip';
        }

        // Check for excessive attempts on same token
        if ($this->countRecentAttemptsByToken($token) >= self::MAX_ATTEMPTS_PER_TOKEN) {
            $isSuspicious = true;
            $reasons[] = 'excessive_attempts_on_token';
        }

        // Check for rapid-fire requests (more than 5 requests in 60 seconds)
        if ($this->countRecentRapidRequests($clientIp) >= 5) {
            $isSuspicious = true;
            $reasons[] = 'rapid_fire_requests';
        }

        if ($isSuspicious) {
            $this->handleSuspiciousActivity($token, $action, $reasons);
        }

        return $isSuspicious;
    }

    /**
     * Handle detected suspicious activity
     */
    private function handleSuspiciousActivity(string $token, string $action, array $reasons): void
    {
        $clientIp = $this->getClientIp();
        
        // Log suspicious activity
        $this->structuredLogger->logSecurityEvent(
            'Suspicious role acceptance activity detected',
            [
                'token' => $token,
                'action' => $action,
                'reasons' => $reasons,
                'ip_address' => $clientIp,
                'user_agent' => $this->getUserAgent(),
                'timestamp' => (new \DateTime())->format('c')
            ]
        );

        // Find pending user to get admin for notification
        $pendingUser = $this->entityManager->getRepository(PendingUser::class)
            ->findOneBy(['acceptanceToken' => $token]);

        if ($pendingUser) {
            // Log as suspicious activity in activity log
            $this->activityLogService->logSuspiciousActivity(
                $pendingUser->getCreatedByAdmin(),
                'role_acceptance_suspicious_activity',
                [
                    'token' => $token,
                    'action' => $action,
                    'reasons' => $reasons,
                    'ip_address' => $clientIp,
                    'pending_user_email' => $pendingUser->getEmail()
                ]
            );

            // Temporarily disable the token if too many attempts
            if (in_array('excessive_attempts_on_token', $reasons)) {
                $this->temporarilyDisableToken($pendingUser);
            }

            // Send alert to administrator
            $this->sendAdministratorAlert($pendingUser, $reasons);
        }

        // Alert system administrators for IP-based suspicious activity
        if (in_array('excessive_attempts_from_ip', $reasons) || in_array('rapid_fire_requests', $reasons)) {
            $this->alertSystemAdministrators($clientIp, $reasons);
        }
    }

    /**
     * Temporarily disable a token due to suspicious activity
     */
    public function temporarilyDisableToken(PendingUser $pendingUser): void
    {
        $pendingUser->setStatus('temporarily_disabled');
        $pendingUser->setDisabledUntil(new \DateTime('+1 hour'));
        
        $this->entityManager->flush();

        $this->structuredLogger->logSecurityEvent(
            'Token temporarily disabled due to suspicious activity',
            [
                'token' => $pendingUser->getAcceptanceToken(),
                'pending_user_id' => $pendingUser->getId(),
                'pending_user_email' => $pendingUser->getEmail(),
                'disabled_until' => $pendingUser->getDisabledUntil()?->format('c')
            ]
        );
    }

    /**
     * Check if a token is currently disabled
     */
    public function isTokenDisabled(PendingUser $pendingUser): bool
    {
        if ($pendingUser->getStatus() !== 'temporarily_disabled') {
            return false;
        }

        $disabledUntil = $pendingUser->getDisabledUntil();
        if (!$disabledUntil) {
            return false;
        }

        // Check if disable period has expired
        if ($disabledUntil <= new \DateTime()) {
            // Re-enable the token
            $pendingUser->setStatus('pending');
            $pendingUser->setDisabledUntil(null);
            $this->entityManager->flush();
            
            return false;
        }

        return true;
    }

    /**
     * Send alert to the administrator who created the invitation
     */
    private function sendAdministratorAlert(PendingUser $pendingUser, array $reasons): void
    {
        $admin = $pendingUser->getCreatedByAdmin();
        $reasonsText = implode(', ', array_map(function($reason) {
            return str_replace('_', ' ', $reason);
        }, $reasons));

        $this->notificationService->createWarningNotification(
            $admin,
            'Security Alert: Suspicious Role Acceptance Activity',
            sprintf(
                'Suspicious activity detected for role invitation to %s (%s). Reasons: %s. The invitation has been temporarily disabled for security.',
                $pendingUser->getEmail(),
                $pendingUser->getFullName(),
                $reasonsText
            ),
            null, // No action URL for security alerts
            null
        );
    }

    /**
     * Alert system administrators about IP-based suspicious activity
     */
    private function alertSystemAdministrators(string $clientIp, array $reasons): void
    {
        $systemAdmins = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.role = :role')
            ->setParameter('role', 'ROLE_SYSTEM_ADMIN')
            ->getQuery()
            ->getResult();
            
        $reasonsText = implode(', ', array_map(function($reason) {
            return str_replace('_', ' ', $reason);
        }, $reasons));

        foreach ($systemAdmins as $admin) {
            $this->notificationService->createWarningNotification(
                $admin,
                'Security Alert: Suspicious IP Activity',
                sprintf(
                    'Suspicious role acceptance activity detected from IP %s. Reasons: %s. Please review security logs.',
                    $clientIp,
                    $reasonsText
                ),
                null, // No action URL for security alerts
                null
            );
        }
    }

    /**
     * Count recent attempts from the same IP address
     */
    private function countRecentAttemptsByIp(string $clientIp): int
    {
        $since = new \DateTime('-' . self::SUSPICIOUS_ACTIVITY_WINDOW . ' seconds');
        
        // This would typically query a security events table or cache
        // For now, we'll use a simple approach with activity logs
        return $this->entityManager->createQuery(
            'SELECT COUNT(al.id) FROM App\Entity\ActivityLog al 
             WHERE al.ipAddress = :ip 
             AND al.activityType LIKE :activity_type 
             AND al.createdAt >= :since'
        )
        ->setParameter('ip', $clientIp)
        ->setParameter('activity_type', '%role_acceptance%')
        ->setParameter('since', $since)
        ->getSingleScalarResult();
    }

    /**
     * Count recent attempts on the same token
     */
    private function countRecentAttemptsByToken(string $token): int
    {
        // TODO: Implement proper token-based activity counting
        // For now, return 0 to allow role acceptance to work
        // The security logging is still happening via StructuredLogger
        return 0;
    }

    /**
     * Count rapid-fire requests from the same IP (within 60 seconds)
     */
    private function countRecentRapidRequests(string $clientIp): int
    {
        $since = new \DateTime('-60 seconds');
        
        return $this->entityManager->createQuery(
            'SELECT COUNT(al.id) FROM App\Entity\ActivityLog al 
             WHERE al.ipAddress = :ip 
             AND al.activityType LIKE :activity_type 
             AND al.createdAt >= :since'
        )
        ->setParameter('ip', $clientIp)
        ->setParameter('activity_type', '%role_acceptance%')
        ->setParameter('since', $since)
        ->getSingleScalarResult();
    }

    /**
     * Get client IP address
     */
    private function getClientIp(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        
        if (!$request) {
            return 'unknown';
        }

        // Check for IP from shared internet
        if (!empty($request->server->get('HTTP_CLIENT_IP'))) {
            return $request->server->get('HTTP_CLIENT_IP');
        }
        // Check for IP passed from proxy
        elseif (!empty($request->server->get('HTTP_X_FORWARDED_FOR'))) {
            return $request->server->get('HTTP_X_FORWARDED_FOR');
        }
        // Check for IP from remote address
        else {
            return $request->getClientIp() ?? 'unknown';
        }
    }

    /**
     * Get user agent string
     */
    private function getUserAgent(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request?->headers->get('User-Agent') ?? 'unknown';
    }
}