<?php

namespace App\Exceptions\Configuration;

class InvalidRolloutTransitionException extends \RuntimeException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            "Cannot transition rollout status from '{$from}' to '{$to}'."
        );
    }
}
