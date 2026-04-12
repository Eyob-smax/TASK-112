<?php

namespace App\Exceptions\Workflow;

class WorkflowTerminatedException extends \RuntimeException
{
    public function __construct(string $message = 'This workflow instance has already reached a terminal state and cannot be modified.')
    {
        parent::__construct($message);
    }
}
