<?php

namespace App\Exceptions\Configuration;

class CanaryStoreCountMisconfiguredException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Canary store rollout is misconfigured: configure non-empty CANARY_STORE_IDS and ensure CANARY_STORE_COUNT is greater than zero.');
    }
}
