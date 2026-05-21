<?php

namespace App\Exception;

use App\Entity\Enum\WorkflowState;
use Exception;

class WorkflowStateException extends Exception
{
    private WorkflowState $currentState;
    private WorkflowState $requiredState;

    public function __construct(
        WorkflowState $currentState,
        WorkflowState $requiredState,
        string $message = '',
        int $code = 0,
        ?Exception $previous = null
    ) {
        $this->currentState = $currentState;
        $this->requiredState = $requiredState;

        if (empty($message)) {
            $message = sprintf(
                'Invalid workflow state. Current state: %s, Required state: %s',
                $currentState->value,
                $requiredState->value
            );
        }

        parent::__construct($message, $code, $previous);
    }

    public function getCurrentState(): WorkflowState
    {
        return $this->currentState;
    }

    public function getRequiredState(): WorkflowState
    {
        return $this->requiredState;
    }
}
