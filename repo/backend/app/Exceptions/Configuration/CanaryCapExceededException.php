<?php

namespace App\Exceptions\Configuration;

class CanaryCapExceededException extends \RuntimeException
{
    public function __construct(int $requested, int $maxAllowed)
    {
        parent::__construct(
            "Canary rollout cap exceeded: {$requested} targets requested but maximum allowed is {$maxAllowed} (10% of eligible population)."
        );
    }
}
