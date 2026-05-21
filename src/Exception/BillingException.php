<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class BillingException extends HttpException
{
    public function __construct(string $message = 'Billing calculation error occurred', \Throwable $previous = null, int $code = 0, array $headers = [])
    {
        parent::__construct(400, $message, $previous, $headers, $code);
    }
}
