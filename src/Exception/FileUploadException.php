<?php

namespace App\Exception;

use Exception;

/**
 * Exception thrown when file upload validation or storage fails
 */
class FileUploadException extends Exception
{
    public const INVALID_FILE_FORMAT = 'INVALID_FILE_FORMAT';
    public const FILE_SIZE_EXCEEDED = 'FILE_SIZE_EXCEEDED';
    public const FILE_UPLOAD_FAILED = 'FILE_UPLOAD_FAILED';
    public const INVALID_MIME_TYPE = 'INVALID_MIME_TYPE';

    private string $errorCode;

    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatusCode = 400,
        ?Exception $previous = null
    ) {
        $this->errorCode = $errorCode;
        parent::__construct($message, $httpStatusCode, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->getCode();
    }
}
