<?php

namespace App\Exceptions\Workflow;

class WorkflowNodeNotActionableException extends \RuntimeException
{
    public function __construct(string $message = 'This workflow node is not in an actionable state.')
    {
        parent::__construct($message);
    }
}
