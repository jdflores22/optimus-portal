<?php

namespace App\Controller;

use App\Service\PendingUserService;
use App\Service\EmailNotificationService;
use App\Service\InAppNotificationService;
use App\Service\RoleAcceptanceSecurityService;
use App\Service\RoleAcceptanceCSRFService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpFoundation\RequestStack;

class RoleAcceptanceController extends AbstractController
{
    public function __construct(
        private PendingUserService $pendingUserService,
        private EmailNotificationService $emailNotificationService,
        private InAppNotificationService $inAppNotificationService,
        private RoleAcceptanceSecurityService $securityService,
        private RoleAcceptanceCSRFService $csrfService,
        private LoggerInterface $logger,
        private RateLimiterFactory $roleAcceptanceLimiter,
        private RequestStack $requestStack
    ) {
    }

    #[Route('/role-acceptance/{token}', name: 'role_acceptance_page', methods: ['GET'])]
    public function showAcceptancePage(string $token): Response
    {
        try {
            // Log page access attempt
            $this->securityService->logRoleAcceptanceActivity('page_access', $token);

            // Check for suspicious activity
            if ($this->securityService->detectSuspiciousActivity($token, 'page_access')) {
                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'suspicious_activity',
                    'message' => 'Suspicious activity detected. Access temporarily restricted for security reasons.'
                ]);
            }

            // Find pending user by token
            $pendingUser = $this->pendingUserService->findByToken($token);

            if (!$pendingUser) {
                $this->logger->warning('Role acceptance page accessed with invalid token', [
                    'token' => $token,
                    'ip' => $this->getClientIp()
                ]);

                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'invalid_token',
                    'message' => 'The role acceptance link is invalid or has been used already.'
                ]);
            }

            // Check if token is temporarily disabled due to security
            if ($this->securityService->isTokenDisabled($pendingUser)) {
                $this->securityService->logRoleAcceptanceActivity('access_blocked_disabled', $token, $pendingUser);
                
                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'temporarily_disabled',
                    'message' => 'This invitation has been temporarily disabled due to suspicious activity. Please contact your administrator.',
                    'admin_contact' => $pendingUser->getCreatedByAdmin()->getEmail()
                ]);
            }

            // Check if token is expired or already processed
            if (!$pendingUser->canBeProcessed()) {
                $this->logger->info('Role acceptance page accessed with expired/processed token', [
                    'token' => $token,
                    'status' => $pendingUser->getStatus(),
                    'expires_at' => $pendingUser->getTokenExpiresAt()->format('Y-m-d H:i:s'),
                    'ip' => $this->getClientIp()
                ]);

                // Show specific templates based on the status
                if ($pendingUser->getStatus() === 'accepted') {
                    return $this->render('role_acceptance/already_accepted.html.twig', [
                        'pendingUser' => $pendingUser
                    ]);
                } elseif ($pendingUser->getStatus() === 'declined') {
                    return $this->render('role_acceptance/already_declined.html.twig', [
                        'pendingUser' => $pendingUser
                    ]);
                } else {
                    // For expired or other statuses, show the generic error
                    $errorType = $pendingUser->isExpired() ? 'expired_token' : 'already_processed';
                    $message = $pendingUser->isExpired() 
                        ? 'This role acceptance link has expired. Please contact your administrator for a new invitation.'
                        : 'This role invitation has already been processed.';

                    return $this->render('role_acceptance/error.html.twig', [
                        'error_type' => $errorType,
                        'message' => $message,
                        'admin_contact' => $pendingUser->getCreatedByAdmin()->getEmail()
                    ]);
                }
            }

            // Log successful page access
            $this->securityService->logRoleAcceptanceActivity('page_view_success', $token, $pendingUser);

            // Generate CSRF token for form submissions
            $csrfToken = $this->csrfService->generateToken($token);

            return $this->render('role_acceptance/show.html.twig', [
                'pendingUser' => $pendingUser,
                'token' => $token,
                'csrf_token' => $csrfToken,
                'expires_at' => $pendingUser->getTokenExpiresAt(),
                'admin_contact' => $pendingUser->getCreatedByAdmin()->getEmail(),
                'shipping_line' => $pendingUser->getShippingLine()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error displaying role acceptance page', [
                'token' => $token,
                'error' => $e->getMessage(),
                'ip' => $this->getClientIp()
            ]);

            return $this->render('role_acceptance/error.html.twig', [
                'error_type' => 'system_error',
                'message' => 'An error occurred while loading the page. Please try again later or contact support.'
            ]);
        }
    }

    #[Route('/role-acceptance/{token}/accept', name: 'role_acceptance_accept', methods: ['POST'])]
    public function acceptRole(string $token, Request $request): Response
    {
        try {
            // Enhanced rate limiting check using injected limiter
            $limit = $this->roleAcceptanceLimiter->create($this->getClientIp())->consume();
            
            if (!$limit->isAccepted()) {
                $this->securityService->logRoleAcceptanceActivity('rate_limit_exceeded', $token, null, [
                    'action' => 'accept',
                    'remaining_attempts' => $limit->getRemainingTokens()
                ]);

                $this->addFlash('error', 'Too many attempts. Please wait a moment before trying again.');
                return $this->redirectToRoute('role_acceptance_page', ['token' => $token]);
            }

            // Check for suspicious activity before processing
            if ($this->securityService->detectSuspiciousActivity($token, 'accept_attempt')) {
                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'suspicious_activity',
                    'message' => 'Suspicious activity detected. Access temporarily restricted for security reasons.'
                ]);
            }

            // Enhanced CSRF protection with attack detection
            if ($this->csrfService->detectCSRFAttack($request, $token)) {
                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'suspicious_activity',
                    'message' => 'Security violation detected. Please contact your administrator.'
                ]);
            }

            // Find pending user first for better error context
            $pendingUser = $this->pendingUserService->findByToken($token);

            if (!$pendingUser) {
                $this->securityService->logRoleAcceptanceActivity('invalid_token_accept', $token);
                
                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'invalid_token',
                    'message' => 'The role acceptance link is invalid, expired, or has already been used.'
                ]);
            }

            // Validate CSRF token with enhanced logging
            $csrfValidation = $this->csrfService->validateToken($request, $token, $pendingUser);
            if (!$csrfValidation['valid']) {
                $this->addFlash('error', $csrfValidation['message']);
                return $this->redirectToRoute('role_acceptance_page', ['token' => $token]);
            }

            // Check if token is temporarily disabled
            if ($this->securityService->isTokenDisabled($pendingUser)) {
                $this->securityService->logRoleAcceptanceActivity('accept_blocked_disabled', $token, $pendingUser);
                
                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'temporarily_disabled',
                    'message' => 'This invitation has been temporarily disabled due to suspicious activity. Please contact your administrator.',
                    'admin_contact' => $pendingUser->getCreatedByAdmin()->getEmail()
                ]);
            }

            if (!$pendingUser->canBeProcessed()) {
                $this->securityService->logRoleAcceptanceActivity('invalid_state_accept', $token, $pendingUser);

                // Show specific templates based on the status
                if ($pendingUser->getStatus() === 'accepted') {
                    return $this->render('role_acceptance/already_accepted.html.twig', [
                        'pendingUser' => $pendingUser
                    ]);
                } elseif ($pendingUser->getStatus() === 'declined') {
                    return $this->render('role_acceptance/already_declined.html.twig', [
                        'pendingUser' => $pendingUser
                    ]);
                } else {
                    return $this->render('role_acceptance/error.html.twig', [
                        'error_type' => 'invalid_token',
                        'message' => 'The role acceptance link is invalid, expired, or has already been used.'
                    ]);
                }
            }

            // Accept the role and create user account
            $newUser = $this->pendingUserService->acceptRole($pendingUser);

            // Log successful acceptance
            $this->securityService->logRoleAcceptanceActivity('role_accepted', $token, $pendingUser, [
                'new_user_id' => $newUser->getId()
            ]);

            // Send welcome email to new user
            $this->emailNotificationService->sendWelcomeEmail($newUser);

            // Send email notification to admin
            $this->emailNotificationService->sendRoleAcceptedNotification(
                $pendingUser->getCreatedByAdmin(),
                $newUser
            );

            // Send in-app notification to admin (Requirement 5.1)
            $this->inAppNotificationService->createSuccessNotification(
                $pendingUser->getCreatedByAdmin(),
                'User Role Accepted',
                sprintf(
                    '%s (%s) has accepted the %s role for %s.',
                    $newUser->getEmail(),
                    $pendingUser->getFullName(),
                    $pendingUser->getRole()->value,
                    $pendingUser->getShippingLine() ? $pendingUser->getShippingLine()->getName() : 'the system'
                ),
                $this->generateUrl('admin_user_hierarchy_detail', ['id' => $newUser->getId()]),
                'View User Profile'
            );

            return $this->render('role_acceptance/success.html.twig', [
                'user' => $newUser,
                'role' => $pendingUser->getRole(),
                'shipping_line' => $pendingUser->getShippingLine()
            ]);

        } catch (\Exception $e) {
            $this->securityService->logRoleAcceptanceActivity('accept_error', $token, null, [
                'error' => $e->getMessage()
            ]);

            $this->addFlash('error', 'An error occurred while processing your acceptance. Please try again or contact support.');
            return $this->redirectToRoute('role_acceptance_page', ['token' => $token]);
        }
    }

    #[Route('/role-acceptance/{token}/decline', name: 'role_acceptance_decline', methods: ['POST'])]
    public function declineRole(string $token, Request $request): Response
    {
        try {
            // Enhanced rate limiting check using injected limiter
            $limit = $this->roleAcceptanceLimiter->create($this->getClientIp())->consume();
            
            if (!$limit->isAccepted()) {
                $this->securityService->logRoleAcceptanceActivity('rate_limit_exceeded', $token, null, [
                    'action' => 'decline',
                    'remaining_attempts' => $limit->getRemainingTokens()
                ]);

                $this->addFlash('error', 'Too many attempts. Please wait a moment before trying again.');
                return $this->redirectToRoute('role_acceptance_page', ['token' => $token]);
            }

            // Check for suspicious activity before processing
            if ($this->securityService->detectSuspiciousActivity($token, 'decline_attempt')) {
                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'suspicious_activity',
                    'message' => 'Suspicious activity detected. Access temporarily restricted for security reasons.'
                ]);
            }

            // Enhanced CSRF protection with attack detection
            if ($this->csrfService->detectCSRFAttack($request, $token)) {
                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'suspicious_activity',
                    'message' => 'Security violation detected. Please contact your administrator.'
                ]);
            }

            // Find pending user first for better error context
            $pendingUser = $this->pendingUserService->findByToken($token);

            if (!$pendingUser) {
                $this->securityService->logRoleAcceptanceActivity('invalid_token_decline', $token);

                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'invalid_token',
                    'message' => 'The role acceptance link is invalid, expired, or has already been used.'
                ]);
            }

            // Validate CSRF token with enhanced logging
            $csrfValidation = $this->csrfService->validateToken($request, $token, $pendingUser);
            if (!$csrfValidation['valid']) {
                $this->addFlash('error', $csrfValidation['message']);
                return $this->redirectToRoute('role_acceptance_page', ['token' => $token]);
            }

            // Check if token is temporarily disabled
            if ($this->securityService->isTokenDisabled($pendingUser)) {
                $this->securityService->logRoleAcceptanceActivity('decline_blocked_disabled', $token, $pendingUser);
                
                return $this->render('role_acceptance/error.html.twig', [
                    'error_type' => 'temporarily_disabled',
                    'message' => 'This invitation has been temporarily disabled due to suspicious activity. Please contact your administrator.',
                    'admin_contact' => $pendingUser->getCreatedByAdmin()->getEmail()
                ]);
            }

            if (!$pendingUser->canBeProcessed()) {
                $this->securityService->logRoleAcceptanceActivity('invalid_state_decline', $token, $pendingUser);

                // Show specific templates based on the status
                if ($pendingUser->getStatus() === 'accepted') {
                    return $this->render('role_acceptance/already_accepted.html.twig', [
                        'pendingUser' => $pendingUser
                    ]);
                } elseif ($pendingUser->getStatus() === 'declined') {
                    return $this->render('role_acceptance/already_declined.html.twig', [
                        'pendingUser' => $pendingUser
                    ]);
                } else {
                    return $this->render('role_acceptance/error.html.twig', [
                        'error_type' => 'invalid_token',
                        'message' => 'The role acceptance link is invalid, expired, or has already been used.'
                    ]);
                }
            }

            // Decline the role
            $this->pendingUserService->declineRole($pendingUser);

            // Log successful decline
            $this->securityService->logRoleAcceptanceActivity('role_declined', $token, $pendingUser);

            // Send email notification to admin
            $this->emailNotificationService->sendRoleDeclinedNotification(
                $pendingUser->getCreatedByAdmin(),
                $pendingUser
            );

            // Send in-app notification to admin (Requirement 5.2)
            $this->inAppNotificationService->createWarningNotification(
                $pendingUser->getCreatedByAdmin(),
                'User Role Declined',
                sprintf(
                    '%s (%s) has declined the %s role for %s.',
                    $pendingUser->getEmail(),
                    $pendingUser->getFullName(),
                    $pendingUser->getRole()->value,
                    $pendingUser->getShippingLine() ? $pendingUser->getShippingLine()->getName() : 'the system'
                ),
                $this->generateUrl('admin_user_hierarchy_list'),
                'Manage Invitations'
            );

            return $this->render('role_acceptance/declined.html.twig', [
                'pendingUser' => $pendingUser,
                'admin_contact' => $pendingUser->getCreatedByAdmin()->getEmail()
            ]);

        } catch (\Exception $e) {
            $this->securityService->logRoleAcceptanceActivity('decline_error', $token, null, [
                'error' => $e->getMessage()
            ]);

            $this->addFlash('error', 'An error occurred while processing your decline. Please try again or contact support.');
            return $this->redirectToRoute('role_acceptance_page', ['token' => $token]);
        }
    }

    /**
     * Get client IP address for logging and rate limiting
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
}