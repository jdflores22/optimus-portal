<?php

namespace App\Service;

use App\Exception\AuthorizationException;
use App\Exception\BusinessLogicException;
use App\Exception\ManifestValidationException;
use App\Exception\NotFoundException;
use App\Exception\PaymentValidationException;
use App\Exception\UnauthorizedActionException;
use App\Exception\ValidationException;
use App\Exception\WorkflowStateException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Throwable;
use Twig\Environment;

class ErrorHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
        private Environment $twig,
        private NotificationService $notificationService,
        private StructuredLogger $structuredLogger,
        private string $environment = 'prod'
    ) {
    }

    public function handleException(Throwable $e): Response
    {
        $request = $this->requestStack->getCurrentRequest();
        $user = $this->tokenStorage->getToken()?->getUser();

        // Log all exceptions with context
        $context = [
            'exception' => $e,
            'user' => $user ? $user->getUserIdentifier() : 'anonymous',
            'request_uri' => $request?->getRequestUri(),
            'request_method' => $request?->getMethod(),
            'ip_address' => $request?->getClientIp(),
            'user_agent' => $request?->headers->get('User-Agent'),
        ];

        $this->logger->error($e->getMessage(), $context);
        $this->structuredLogger->logError($e->getMessage(), $e, $context);

        // Alert administrators for critical system errors
        if (!($e instanceof ValidationException) && 
            !($e instanceof AuthorizationException) && 
            !($e instanceof NotFoundException) && 
            !($e instanceof BusinessLogicException) &&
            !($e instanceof ManifestValidationException) &&
            !($e instanceof WorkflowStateException) &&
            !($e instanceof UnauthorizedActionException) &&
            !($e instanceof PaymentValidationException)) {
            $this->alertAdministrators($e, $context);
        }

        // Map exception types to HTTP responses
        return match (true) {
            $e instanceof ValidationException => $this->validationError($e, $request),
            $e instanceof ManifestValidationException => $this->manifestValidationError($e, $request),
            $e instanceof PaymentValidationException => $this->paymentValidationError($e, $request),
            $e instanceof WorkflowStateException => $this->workflowStateError($e, $request),
            $e instanceof UnauthorizedActionException => $this->unauthorizedActionError($e, $request),
            $e instanceof AuthorizationException => $this->authorizationError($e, $request),
            $e instanceof AuthenticationException => $this->authenticationError($e, $request),
            $e instanceof NotFoundException => $this->notFoundError($e, $request),
            $e instanceof BusinessLogicException => $this->businessLogicError($e, $request),
            default => $this->systemError($e, $request)
        };
    }

    private function validationError(ValidationException $e, ?Request $request): Response
    {
        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => 'Validation failed',
                'message' => $e->getMessage(),
                'errors' => $e->getErrors()
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderErrorPage(422, 'Validation Error', $e->getMessage());
    }

    private function authorizationError(AuthorizationException $e, ?Request $request): Response
    {
        // Log unauthorized access attempts
        $this->structuredLogger->logSecurityEvent('Unauthorized access attempt', [
            'message' => $e->getMessage(),
            'user' => $this->tokenStorage->getToken()?->getUser()?->getUserIdentifier(),
            'request_uri' => $request?->getRequestUri(),
            'ip_address' => $request?->getClientIp(),
        ]);

        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => 'Access denied',
                'message' => 'You do not have permission to access this resource'
            ], Response::HTTP_FORBIDDEN);
        }

        return $this->renderErrorPage(403, 'Access Denied', 'You do not have permission to access this resource.');
    }

    private function authenticationError(AuthenticationException $e, ?Request $request): Response
    {
        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => 'Authentication required',
                'message' => 'Please log in to access this resource'
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $this->renderErrorPage(401, 'Authentication Required', 'Please log in to access this resource.');
    }

    private function notFoundError(NotFoundException $e, ?Request $request): Response
    {
        // Log if pattern suggests enumeration attack
        $uri = $request?->getRequestUri() ?? '';
        if (preg_match('/\/\d+$/', $uri)) {
            $this->structuredLogger->logSecurityEvent('Potential enumeration attempt', [
                'request_uri' => $uri,
                'ip_address' => $request?->getClientIp(),
            ]);
        }

        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => 'Not found',
                'message' => 'The requested resource was not found'
            ], Response::HTTP_NOT_FOUND);
        }

        return $this->renderErrorPage(404, 'Page Not Found', 'The requested resource was not found.');
    }

    private function businessLogicError(BusinessLogicException $e, ?Request $request): Response
    {
        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => 'Business rule violation',
                'message' => $e->getMessage()
            ], Response::HTTP_CONFLICT);
        }

        return $this->renderErrorPage(409, 'Operation Not Allowed', $e->getMessage());
    }

    private function manifestValidationError(ManifestValidationException $e, ?Request $request): Response
    {
        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => 'Manifest validation failed',
                'message' => $e->getMessage(),
                'code' => 'MANIFEST_VALIDATION_ERROR',
                'details' => $e->getValidationErrors(),
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->renderErrorPage(400, 'Manifest Validation Error', $e->getMessage());
    }

    private function paymentValidationError(PaymentValidationException $e, ?Request $request): Response
    {
        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => 'Payment validation failed',
                'message' => $e->getMessage(),
                'code' => 'PAYMENT_VALIDATION_ERROR',
                'details' => $e->getPaymentErrors(),
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ], Response::HTTP_BAD_REQUEST);
        }

        return $this->renderErrorPage(400, 'Payment Validation Error', $e->getMessage());
    }

    private function workflowStateError(WorkflowStateException $e, ?Request $request): Response
    {
        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => 'Invalid workflow state',
                'message' => $e->getMessage(),
                'code' => 'WORKFLOW_STATE_ERROR',
                'details' => [
                    'currentState' => $e->getCurrentState()->value,
                    'requiredState' => $e->getRequiredState()->value
                ],
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ], Response::HTTP_CONFLICT);
        }

        return $this->renderErrorPage(409, 'Workflow State Error', $e->getMessage());
    }

    private function unauthorizedActionError(UnauthorizedActionException $e, ?Request $request): Response
    {
        // Log unauthorized action attempts
        $this->structuredLogger->logSecurityEvent('Unauthorized action attempt', [
            'message' => $e->getMessage(),
            'action' => $e->getAction(),
            'user_role' => $e->getUserRole(),
            'user' => $this->tokenStorage->getToken()?->getUser()?->getUserIdentifier(),
            'request_uri' => $request?->getRequestUri(),
            'ip_address' => $request?->getClientIp(),
        ]);

        if ($this->isJsonRequest($request)) {
            return new JsonResponse([
                'error' => 'Unauthorized action',
                'message' => $e->getMessage(),
                'code' => 'UNAUTHORIZED_ACTION',
                'details' => [
                    'action' => $e->getAction(),
                    'userRole' => $e->getUserRole()
                ],
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ], Response::HTTP_FORBIDDEN);
        }

        return $this->renderErrorPage(403, 'Unauthorized Action', $e->getMessage());
    }

    private function systemError(Throwable $e, ?Request $request): Response
    {
        if ($this->isJsonRequest($request)) {
            $message = $this->environment === 'dev' ? $e->getMessage() : 'An internal server error occurred';
            return new JsonResponse([
                'error' => 'Internal server error',
                'message' => $message
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $message = $this->environment === 'dev' ? $e->getMessage() : 'An unexpected error occurred. Please try again later.';
        return $this->renderErrorPage(500, 'Internal Server Error', $message);
    }

    private function renderErrorPage(int $statusCode, string $title, string $message): Response
    {
        try {
            $content = $this->twig->render('error/error.html.twig', [
                'status_code' => $statusCode,
                'title' => $title,
                'message' => $message
            ]);
            return new Response($content, $statusCode);
        } catch (Throwable $e) {
            // Fallback if template rendering fails
            return new Response(
                sprintf('<h1>%s</h1><p>%s</p>', htmlspecialchars($title), htmlspecialchars($message)),
                $statusCode
            );
        }
    }

    private function isJsonRequest(?Request $request): bool
    {
        if (!$request) {
            return false;
        }

        return $request->headers->get('Content-Type') === 'application/json' ||
               $request->headers->get('Accept') === 'application/json' ||
               str_starts_with($request->getPathInfo(), '/api/');
    }

    private function alertAdministrators(Throwable $e, array $context): void
    {
        try {
            // Send critical error notification to administrators
            $this->notificationService->sendCriticalErrorAlert($e, $context);
        } catch (Throwable $notificationError) {
            // Log notification failure but don't throw
            $this->logger->error('Failed to send administrator alert', [
                'original_exception' => $e->getMessage(),
                'notification_error' => $notificationError->getMessage()
            ]);
        }
    }
}