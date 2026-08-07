<?php

namespace App\EventSubscriber;

use App\Exception\BillingException;
use App\Exception\EDOWorkflowException;
use App\Exception\ManifestValidationException;
use App\Exception\NOAValidationException;
use App\Exception\PaymentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $environment,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request = $event->getRequest();
        
        // Generate correlation ID for tracking
        $correlationId = uniqid('err_', true);
        
        // Log the exception with correlation ID
        $this->logException($exception, $correlationId, $request->getPathInfo());
        
        // Determine status code and user-friendly message
        [$statusCode, $message] = $this->getResponseData($exception);
        
        // Check if request expects JSON response
        $contentType = $request->getContentTypeFormat();
        $isJsonRequest = $contentType === 'json' 
            || $request->headers->get('Accept') === 'application/json'
            || str_starts_with($request->getPathInfo(), '/api/');
        
        // Handle 403 Forbidden - redirect to login for HTML requests
        if ($statusCode === Response::HTTP_FORBIDDEN && !$isJsonRequest) {
            $loginUrl = $this->urlGenerator->generate('app_login');
            $returnUrl = $request->getPathInfo();
            if ($returnUrl && $returnUrl !== '/') {
                $loginUrl .= '?redirect=' . urlencode($returnUrl);
            }

            $response = new Response('', Response::HTTP_FOUND, [
                'Location' => $loginUrl
            ]);
            $event->setResponse($response);
            return;
        }
        
        if ($isJsonRequest) {
            $response = new JsonResponse([
                'error' => true,
                'message' => $message,
                'correlationId' => $correlationId,
                'timestamp' => (new \DateTime())->format('c'),
            ], $statusCode);
        } else {
            // For HTML requests, create a simple error response
            $response = new Response(
                $this->renderErrorPage($statusCode, $message, $correlationId),
                $statusCode
            );
        }
        
        $event->setResponse($response);
    }

    private function getResponseData(\Throwable $exception): array
    {
        // Map exceptions to status codes and user-friendly messages
        $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
        $message = 'An unexpected error occurred. Please try again later.';
        
        if ($exception instanceof NOAValidationException) {
            $statusCode = Response::HTTP_UNPROCESSABLE_ENTITY; // 422
            $message = $exception->getMessage();
        } elseif ($exception instanceof ManifestValidationException) {
            $statusCode = Response::HTTP_BAD_REQUEST;
            $message = $exception->getMessage();
        } elseif ($exception instanceof EDOWorkflowException) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
        } elseif ($exception instanceof BillingException) {
            $statusCode = Response::HTTP_BAD_REQUEST;
            $message = $exception->getMessage();
        } elseif ($exception instanceof PaymentException) {
            $statusCode = Response::HTTP_BAD_REQUEST;
            $message = $exception->getMessage();
        } elseif ($exception instanceof AccessDeniedException || $exception instanceof AccessDeniedHttpException) {
            $statusCode = Response::HTTP_FORBIDDEN;
            $message = 'You do not have permission to access this resource.';
        } elseif ($exception instanceof NotFoundHttpException) {
            $statusCode = Response::HTTP_NOT_FOUND;
            $message = 'The requested resource was not found.';
        } elseif ($exception instanceof HttpExceptionInterface) {
            $statusCode = $exception->getStatusCode();
            $message = $exception->getMessage();
        }
        
        // In production, don't expose internal error details
        if ($this->environment === 'prod' && $statusCode === Response::HTTP_INTERNAL_SERVER_ERROR) {
            $message = 'An unexpected error occurred. Please contact support if the problem persists.';
        }
        
        return [$statusCode, $message];
    }

    private function logException(\Throwable $exception, string $correlationId, string $path): void
    {
        $context = [
            'correlationId' => $correlationId,
            'path' => $path,
            'exceptionClass' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
        
        // Log at appropriate level based on exception type
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            $this->logger->warning('Client error occurred', $context);
        } else {
            $this->logger->error('Server error occurred', $context);
        }
    }

    private function renderErrorPage(int $statusCode, string $message, string $correlationId): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Error {$statusCode}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .error-container { background: white; padding: 30px; border-radius: 8px; max-width: 600px; margin: 0 auto; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #d32f2f; margin-top: 0; }
        .correlation-id { font-size: 12px; color: #666; margin-top: 20px; }
        .back-link { display: inline-block; margin-top: 20px; color: #1976d2; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>Error {$statusCode}</h1>
        <p>{$message}</p>
        <div class="correlation-id">Reference ID: {$correlationId}</div>
        <a href="/" class="back-link">← Return to Home</a>
    </div>
</body>
</html>
HTML;
    }
}
