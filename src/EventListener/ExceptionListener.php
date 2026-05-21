<?php

namespace App\EventListener;

use App\Service\ErrorHandler;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExceptionListener
{
    public function __construct(
        private ErrorHandler $errorHandler
    ) {
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // Don't handle HTTP exceptions (they're already handled by Symfony)
        if ($exception instanceof HttpExceptionInterface) {
            return;
        }

        $response = $this->errorHandler->handleException($exception);
        $event->setResponse($response);
    }
}