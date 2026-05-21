<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Exception thrown when eDO payment operations fail
 */
class EDOPaymentException extends HttpException
{
    // Error codes
    public const INVALID_FILE_FORMAT = 'INVALID_FILE_FORMAT';
    public const FILE_SIZE_EXCEEDED = 'FILE_SIZE_EXCEEDED';
    public const EDO_NOT_FOUND = 'EDO_NOT_FOUND';
    public const PAYMENT_ALREADY_SUBMITTED = 'PAYMENT_ALREADY_SUBMITTED';
    public const EDO_ALREADY_RELEASED = 'EDO_ALREADY_RELEASED';
    public const UNAUTHORIZED_ACCESS = 'UNAUTHORIZED_ACCESS';
    public const CONCURRENT_MODIFICATION = 'CONCURRENT_MODIFICATION';
    public const PAYMENT_NOT_FOUND = 'PAYMENT_NOT_FOUND';
    public const INVALID_PAYMENT_STATUS = 'INVALID_PAYMENT_STATUS';
    public const INVALID_REJECTION_REASON = 'INVALID_REJECTION_REASON';
    public const UNAUTHORIZED_VALIDATION = 'UNAUTHORIZED_VALIDATION';

    private string $errorCode;

    public function __construct(
        string $errorCode,
        string $message,
        int $statusCode = 400,
        ?\Throwable $previous = null,
        array $headers = []
    ) {
        $this->errorCode = $errorCode;
        parent::__construct($statusCode, $message, $previous, $headers);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Create exception for payment already submitted
     */
    public static function paymentAlreadySubmitted(string $edoNumber): self
    {
        return new self(
            self::PAYMENT_ALREADY_SUBMITTED,
            sprintf('Payment already submitted for eDO %s', $edoNumber),
            409
        );
    }

    /**
     * Create exception for eDO already released
     */
    public static function edoAlreadyReleased(string $edoNumber): self
    {
        return new self(
            self::EDO_ALREADY_RELEASED,
            sprintf('eDO %s is already released', $edoNumber),
            409
        );
    }

    /**
     * Create exception for invalid payment status
     */
    public static function invalidPaymentStatus(string $currentStatus, string $expectedStatus): self
    {
        return new self(
            self::INVALID_PAYMENT_STATUS,
            sprintf('Payment status is %s, expected %s', $currentStatus, $expectedStatus),
            409
        );
    }

    /**
     * Create exception for invalid rejection reason
     */
    public static function invalidRejectionReason(): self
    {
        return new self(
            self::INVALID_REJECTION_REASON,
            'Rejection reason must be at least 10 characters',
            400
        );
    }

    /**
     * Create exception for concurrent modification
     */
    public static function concurrentModification(string $edoNumber): self
    {
        return new self(
            self::CONCURRENT_MODIFICATION,
            sprintf('eDO %s was modified by another user. Please refresh and try again.', $edoNumber),
            409
        );
    }
}
