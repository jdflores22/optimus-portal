<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class EDOWorkflowException extends HttpException
{
    public function __construct(string $message = 'eDO workflow error occurred', int $statusCode = 400, \Throwable $previous = null, int $code = 0, array $headers = [])
    {
        parent::__construct($statusCode, $message, $previous, $headers, $code);
    }
}
