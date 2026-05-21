<?php

namespace App\Exception;

use Exception;

class UnauthorizedActionException extends Exception
{
    private string $action;
    private string $userRole;

    public function __construct(
        string $action,
        string $userRole,
        string $message = '',
        int $code = 0,
        ?Exception $previous = null
    ) {
        $this->action = $action;
        $this->userRole = $userRole;

        if (empty($message)) {
            $message = sprintf(
                'Unauthorized action. Action: %s, User role: %s',
                $action,
                $userRole
            );
        }

        parent::__construct($message, $code, $previous);
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getUserRole(): string
    {
        return $this->userRole;
    }
}
