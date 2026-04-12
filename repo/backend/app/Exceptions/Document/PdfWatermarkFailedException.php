<?php

namespace App\Exceptions\Document;

class PdfWatermarkFailedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Unable to deliver a controlled PDF copy because watermarking failed.');
    }
}
