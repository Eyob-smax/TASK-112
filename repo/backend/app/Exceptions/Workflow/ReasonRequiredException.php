<?php

namespace App\Exceptions\Workflow;

class ReasonRequiredException extends \RuntimeException
{
    public function __construct(string $message = 'A reason is required for this workflow action.')
    {
        parent::__construct($message);
    }
}
