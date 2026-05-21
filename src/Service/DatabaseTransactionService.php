<?php

namespace App\Service;

use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DatabaseTransactionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RetryService $retryService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Execute a database operation within a transaction with retry logic for deadlocks
     * 
     * @param callable $operation The database operation to execute
     * @param string $operationName Name of the operation for logging
     * @return mixed The result of the operation
     * @throws \Exception If the operation fails after all retries
     */
    public function executeInTransactionWithRetry(callable $operation, string $operationName = 'database_transaction'): mixed
    {
        return $this->retryService->executeWithRetry(
            function() use ($operation, $operationName) {
                $this->entityManager->beginTransaction();
                
                try {
                    $result = $operation();
                    $this->entityManager->commit();
                    
                    $this->logger->debug("Database transaction completed successfully", [
                        'operation' => $operationName
                    ]);
                    
                    return $result;
                } catch (\Exception $e) {
                    $this->entityManager->rollback();
                    
                    $this->logger->warning("Database transaction rolled back", [
                        'operation' => $operationName,
                        'exception_class' => get_class($e),
                        'exception_message' => $e->getMessage()
                    ]);
                    
                    throw $e;
                }
            },
            3, // Max 3 attempts
            [0.1, 0.5, 1.0], // Short delays for database operations
            [
                DeadlockException::class,
                LockWaitTimeoutException::class,
                ConnectionException::class
            ],
            $operationName
        );
    }

    /**
     * Execute a simple database operation with deadlock retry (no explicit transaction)
     * Useful for single entity operations where Doctrine manages the transaction
     */
    public function executeWithDeadlockRetry(callable $operation, string $operationName = 'database_operation'): mixed
    {
        return $this->retryService->executeWithRetry(
            $operation,
            3, // Max 3 attempts
            [0.1, 0.5, 1.0], // Short delays
            [
                DeadlockException::class,
                LockWaitTimeoutException::class,
                ConnectionException::class
            ],
            $operationName
        );
    }

    /**
     * Execute a bulk operation with retry logic
     * Useful for operations that process multiple entities
     */
    public function executeBulkOperationWithRetry(callable $operation, string $operationName = 'bulk_operation'): mixed
    {
        return $this->executeInTransactionWithRetry(function() use ($operation) {
            $result = $operation();
            
            // Flush changes to database
            $this->entityManager->flush();
            
            return $result;
        }, $operationName);
    }
}