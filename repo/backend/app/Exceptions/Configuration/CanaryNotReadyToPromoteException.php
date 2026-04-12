<?php

namespace App\Exceptions\Configuration;

class CanaryNotReadyToPromoteException extends \RuntimeException
{
    public function __construct(\DateTimeImmutable $earliestAt)
    {
        parent::__construct(
            'Canary rollout cannot be promoted yet. Earliest promotion time: ' . $earliestAt->format('Y-m-d H:i:s') . '.'
        );
    }
}
