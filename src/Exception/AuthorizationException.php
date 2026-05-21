<?php

namespace App\Exception;

use Exception;

class AuthorizationException extends Exception
{
    public function __construct(string $message = 'Access denied', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}