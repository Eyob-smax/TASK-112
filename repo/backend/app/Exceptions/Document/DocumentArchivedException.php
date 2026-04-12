<?php

namespace App\Exceptions\Document;

/**
 * Thrown when a mutating operation is attempted on an archived document.
 *
 * HTTP mapping: 409 Conflict (registered in bootstrap/app.php)
 *
 * Archived documents are frozen — no edits, no new versions, no re-archiving.
 */
class DocumentArchivedException extends \RuntimeException
{
    public function __construct(string $extraMessage = '')
    {
        $base = 'Document is archived and cannot be modified.';

        parent::__construct($extraMessage !== '' ? "{$base} {$extraMessage}" : $base);
    }
}
