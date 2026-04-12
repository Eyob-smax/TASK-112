<?php

namespace App\Domain\Document\Enums;

enum VersionStatus: string
{
    case Current    = 'current';
    case Superseded = 'superseded';
    case Archived   = 'archived';

    /**
     * Whether this version is the active, authoritative copy.
     */
    public function isCurrent(): bool
    {
        return $this === self::Current;
    }

    /**
     * Whether this version can be downloaded as a controlled copy.
     */
    public function isDownloadable(): bool
    {
        return match ($this) {
            self::Current, self::Superseded => true,
            self::Archived                  => false,
        };
    }
}
