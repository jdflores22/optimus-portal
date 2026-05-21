<?php

namespace App\EventSubscriber;

use App\Exception\AllocationLockedException;
use App\Exception\BulkImportValidationException;
use App\Exception\ConcurrentModificationException;
use App\Exception\InsufficientCapacityException;
use App\Exception\InvalidAllocationException;
use App\Exception\UnauthorizedAllocationException;
use App\Service\CYAllocationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class CYAllocationExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CYAllocationService $cyAllocationService
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

        // Only handle our custom exceptions
        if (!$this->isHandledException($exception)) {
            return;
        }

        $isJsonRequest = $request->getContentTypeFormat() === 'json' 
            || str_starts_with($request->getPathInfo(), '/api/');

        $response = match (true) {
            $exception instanceof InsufficientCapacityException => $this->handleInsufficientCapacity($exception, $isJsonRequest),
            $exception instanceof AllocationLockedException => $this->handleAllocationLocked($exception, $isJsonRequest),
            $exception instanceof InvalidAllocationException => $this->handleInvalidAllocation($exception, $isJsonRequest),
            $exception instanceof UnauthorizedAllocationException => $this->handleUnauthorizedAllocation($exception, $isJsonRequest),
            $exception instanceof BulkImportValidationException => $this->handleBulkImportValidation($exception, $isJsonRequest),
            $exception instanceof ConcurrentModificationException => $this->handleConcurrentModification($exception, $isJsonRequest),
            default => null,
        };

        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    private function isHandledException(\Throwable $exception): bool
    {
        return $exception instanceof InsufficientCapacityException
            || $exception instanceof AllocationLockedException
            || $exception instanceof InvalidAllocationException
            || $exception instanceof UnauthorizedAllocationException
            || $exception instanceof BulkImportValidationException
            || $exception instanceof ConcurrentModificationException;
    }

    private function handleInsufficientCapacity(
        InsufficientCapacityException $exception,
        bool $isJsonRequest
    ): Response {
        $data = $exception->toArray();

        // Add alternative suggestions (size-specific if container size is provided)
        $alternatives = $this->getAlternativeSuggestions(
            $exception->getRequiredTeu(),
            $exception->getAllocation()?->getShippingLine(),
            $exception->getContainerSize()
        );

        if (!empty($alternatives)) {
            $data['alternative_locations'] = $alternatives;
        }

        if ($isJsonRequest) {
            return new JsonResponse($data, Response::HTTP_BAD_REQUEST);
        }

        // For HTML requests, set flash message and redirect back
        return $this->createHtmlErrorResponse(
            $exception->getMessage(),
            $alternatives,
            Response::HTTP_BAD_REQUEST
        );
    }

    private function handleAllocationLocked(
        AllocationLockedException $exception,
        bool $isJsonRequest
    ): Response {
        $data = $exception->toArray();

        if ($isJsonRequest) {
            return new JsonResponse($data, Response::HTTP_FORBIDDEN);
        }

        return $this->createHtmlErrorResponse(
            $exception->getMessage(),
            [],
            Response::HTTP_FORBIDDEN
        );
    }

    private function handleInvalidAllocation(
        InvalidAllocationException $exception,
        bool $isJsonRequest
    ): Response {
        $data = $exception->toArray();

        if ($isJsonRequest) {
            return new JsonResponse($data, Response::HTTP_NOT_FOUND);
        }

        return $this->createHtmlErrorResponse(
            $exception->getMessage(),
            [],
            Response::HTTP_NOT_FOUND
        );
    }

    private function handleUnauthorizedAllocation(
        UnauthorizedAllocationException $exception,
        bool $isJsonRequest
    ): Response {
        $data = $exception->toArray();

        if ($isJsonRequest) {
            return new JsonResponse($data, Response::HTTP_FORBIDDEN);
        }

        return $this->createHtmlErrorResponse(
            $exception->getMessage(),
            [],
            Response::HTTP_FORBIDDEN
        );
    }

    private function handleBulkImportValidation(
        BulkImportValidationException $exception,
        bool $isJsonRequest
    ): Response {
        $data = $exception->toArray();

        if ($isJsonRequest) {
            return new JsonResponse($data, Response::HTTP_BAD_REQUEST);
        }

        return $this->createHtmlErrorResponse(
            $exception->getMessage(),
            [],
            Response::HTTP_BAD_REQUEST,
            $exception->getErrorSummary()
        );
    }

    private function handleConcurrentModification(
        ConcurrentModificationException $exception,
        bool $isJsonRequest
    ): Response {
        $data = $exception->toArray();

        if ($isJsonRequest) {
            return new JsonResponse($data, Response::HTTP_CONFLICT);
        }

        return $this->createHtmlErrorResponse(
            $exception->getMessage(),
            [],
            Response::HTTP_CONFLICT
        );
    }

    private function getAlternativeSuggestions(float $requiredTeu, $shippingLine, ?string $containerSize = null): array
    {
        if ($shippingLine === null) {
            return [];
        }

        try {
            // If container size is specified, use size-specific filtering
            if ($containerSize !== null) {
                $teuValue = $containerSize === '20ft' ? 1.0 : 2.0;
                $availableAllocations = $this->cyAllocationService->getAvailableAllocationsBySize(
                    $shippingLine,
                    $teuValue
                );
                
                $alternatives = [];
                foreach ($availableAllocations as $data) {
                    $allocation = $data['allocation'];
                    $utilization = $data['utilization'];
                    
                    // Only include if has sufficient capacity
                    if ($utilization->getAvailableTEU() >= 1) {
                        $alternatives[] = [
                            'allocation_id' => $allocation->getId(),
                            'terminal_id' => $allocation->getTerminal()?->getId(),
                            'terminal_name' => $allocation->getTerminal()?->getName(),
                            'container_size' => $containerSize,
                            'available_count' => (int) $utilization->getAvailableTEU(),
                            'utilization_percentage' => round($utilization->getUtilizationPercentage(), 2),
                        ];
                    }
                }
                
                // Already sorted by available capacity in getAvailableAllocationsBySize
                return array_slice($alternatives, 0, 3); // Return top 3 alternatives
            }
            
            // Fallback to TEU-based suggestions
            $availableAllocations = $this->cyAllocationService->getAvailableAllocations($shippingLine);
            
            $alternatives = [];
            foreach ($availableAllocations as $data) {
                $allocation = $data['allocation'];
                $utilization = $this->cyAllocationService->calculateUtilization($allocation);
                
                if ($utilization->getAvailableTeu() >= $requiredTeu) {
                    $alternatives[] = [
                        'allocation_id' => $allocation->getId(),
                        'terminal_id' => $allocation->getTerminal()?->getId(),
                        'terminal_name' => $allocation->getTerminal()?->getName(),
                        'available_teu' => $utilization->getAvailableTeu(),
                        'utilization_percentage' => round($utilization->getUtilizationPercentage(), 2),
                    ];
                }
            }

            // Sort by available capacity (highest first)
            usort($alternatives, fn($a, $b) => $b['available_teu'] <=> $a['available_teu']);

            return array_slice($alternatives, 0, 3); // Return top 3 alternatives
        } catch (\Exception $e) {
            return [];
        }
    }

    private function createHtmlErrorResponse(
        string $message,
        array $alternatives,
        int $statusCode,
        ?string $detailedError = null
    ): Response {
        $content = [
            'error' => true,
            'message' => $message,
            'status_code' => $statusCode,
        ];

        if (!empty($alternatives)) {
            $content['alternatives'] = $alternatives;
        }

        if ($detailedError !== null) {
            $content['detailed_error'] = $detailedError;
        }

        return new JsonResponse($content, $statusCode);
    }
}
