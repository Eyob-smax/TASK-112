<?php

namespace App\Domain\Document\Enums;

enum DocumentStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    /**
     * Whether documents in this status can be modified.
     */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft     => true,
            self::Published => true,
            self::Archived  => false,  // Archived documents are frozen — no further edits
        };
    }

    /**
     * Whether documents in this status can be downloaded as controlled copies.
     */
    public function isDownloadable(): bool
    {
        return match ($this) {
            self::Draft    => false,
            default        => true,
        };
    }

    /**
     * Valid transitions from this status.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft     => [self::Published],
            self::Published => [self::Archived],
            self::Archived  => [],              // Terminal state — no transitions out
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }
}
