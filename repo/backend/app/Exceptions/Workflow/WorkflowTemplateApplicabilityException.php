<?php

namespace App\Exceptions\Workflow;

class WorkflowTemplateApplicabilityException extends \RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("Workflow template is not applicable for this record: {$reason}");
    }
}
