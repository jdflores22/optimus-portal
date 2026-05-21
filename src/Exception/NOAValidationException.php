<?php

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class NOAValidationException extends HttpException
{
    public function __construct(string $message = 'NOA validation failed', \Throwable $previous = null, int $code = 0, array $headers = [])
    {
        parent::__construct(400, $message, $previous, $headers, $code);
    }
}
