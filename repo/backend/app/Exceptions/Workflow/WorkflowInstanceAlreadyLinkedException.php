<?php

namespace App\Exceptions\Workflow;

class WorkflowInstanceAlreadyLinkedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A workflow instance is already linked to this sales document.');
    }
}
