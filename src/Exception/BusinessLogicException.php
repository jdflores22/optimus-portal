<?php

namespace App\Exception;

use Exception;

class BusinessLogicException extends Exception
{
    public function __construct(string $message = 'Business rule violation', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}