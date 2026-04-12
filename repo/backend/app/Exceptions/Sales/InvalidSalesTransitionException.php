<?php

namespace App\Exceptions\Sales;

class InvalidSalesTransitionException extends \RuntimeException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            "Cannot transition sales document status from '{$from}' to '{$to}'."
        );
    }
}
