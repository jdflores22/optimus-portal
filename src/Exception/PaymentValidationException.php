<?php

namespace App\Exception;

use Exception;

class PaymentValidationException extends Exception
{
    private array $paymentErrors;

    public function __construct(array $paymentErrors, string $message = 'Payment validation failed', int $code = 0, ?Exception $previous = null)
    {
        $this->paymentErrors = $paymentErrors;
        parent::__construct($message, $code, $previous);
    }

    public function getPaymentErrors(): array
    {
        return $this->paymentErrors;
    }
}
