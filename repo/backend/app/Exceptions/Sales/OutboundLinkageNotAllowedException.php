<?php

namespace App\Exceptions\Sales;

class OutboundLinkageNotAllowedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Outbound linkage is only permitted for completed sales documents.');
    }
}
