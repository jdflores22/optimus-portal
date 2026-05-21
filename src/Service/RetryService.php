<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

class RetryService
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * Execute a callable with retry logic and exponential backoff
     * 
     * @param callable $operation The operation to execute
     * @param int $maxAttempts Maximum number of attempts
     * @param array $delays Array of delays in seconds for each retry attempt
     * @param array $retryableExceptions Array of exception classes that should trigger a retry
     * @param string $operationName Name of the operation for logging
     * @return mixed The result of the operation
     * @throws \Exception The last exception if all retries fail
     */
    public function executeWithRetry(
        callable $operation,
        int $maxAttempts = 3,
        array $delays = [1, 5, 15],
        array $retryableExceptions = [\Exception::class],
        string $operationName = 'operation'
    ): mixed {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            try {
                $result = $operation();
                
                if ($attempt > 0) {
                    $this->logger->info("Operation succeeded after retry", [
                        'operation' => $operationName,
                        'attempt' => $attempt + 1,
                        'total_attempts' => $maxAttempts
                    ]);
                }
                
                return $result;
            } catch (\Exception $e) {
                $attempt++;
                $lastException = $e;
                
                // Check if this exception type should trigger a retry
                $shouldRetry = false;
                foreach ($retryableExceptions as $retryableException) {
                    if ($e instanceof $retryableException) {
                        $shouldRetry = true;
                        break;
                    }
                }
                
                if (!$shouldRetry) {
                    $this->logger->error("Operation failed with non-retryable exception", [
                        'operation' => $operationName,
                        'attempt' => $attempt,
                        'exception_class' => get_class($e),
                        'exception_message' => $e->getMessage()
                    ]);
                    throw $e;
                }
                
                $this->logger->warning("Operation failed, will retry", [
                    'operation' => $operationName,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage()
                ]);

                if ($attempt < $maxAttempts) {
                    $delay = $delays[$attempt - 1] ?? end($delays);
                    $this->logger->info("Waiting before retry", [
                        'operation' => $operationName,
                        'delay_seconds' => $delay,
                        'next_attempt' => $attempt + 1
                    ]);
                    sleep($delay);
                }
            }
        }

        // All retries failed
        $this->logger->error("Operation failed after all retries", [
            'operation' => $operationName,
            'total_attempts' => $attempt,
            'final_exception_class' => get_class($lastException),
            'final_exception_message' => $lastException->getMessage()
        ]);

        throw $lastException ?? new \RuntimeException("Operation failed after {$attempt} attempts");
    }

    /**
     * Execute with exponential backoff (doubles delay each time)
     */
    public function executeWithExponentialBackoff(
        callable $operation,
        int $maxAttempts = 3,
        int $baseDelay = 1,
        array $retryableExceptions = [\Exception::class],
        string $operationName = 'operation'
    ): mixed {
        $delays = [];
        for ($i = 0; $i < $maxAttempts - 1; $i++) {
            $delays[] = $baseDelay * (2 ** $i);
        }

        return $this->executeWithRetry(
            $operation,
            $maxAttempts,
            $delays,
            $retryableExceptions,
            $operationName
        );
    }

    /**
     * Execute database operation with deadlock retry
     */
    public function executeDbOperationWithRetry(
        callable $operation,
        string $operationName = 'database_operation'
    ): mixed {
        return $this->executeWithRetry(
            $operation,
            3,
            [0.1, 0.5, 1], // Short delays for database operations
            [
                \Doctrine\DBAL\Exception\DeadlockException::class,
                \Doctrine\DBAL\Exception\LockWaitTimeoutException::class,
                \Doctrine\DBAL\Exception\ConnectionException::class
            ],
            $operationName
        );
    }
}