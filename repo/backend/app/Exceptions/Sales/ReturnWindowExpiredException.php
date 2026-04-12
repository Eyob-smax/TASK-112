<?php

namespace App\Exceptions\Sales;

class ReturnWindowExpiredException extends \RuntimeException
{
    public function __construct(int $daysElapsed, int $windowDays)
    {
        parent::__construct(
            "Return window has expired: {$daysElapsed} days have elapsed since the original sale (qualifying window: {$windowDays} days)."
        );
    }
}
