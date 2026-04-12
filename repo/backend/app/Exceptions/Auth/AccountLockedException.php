<?php

namespace App\Exceptions\Auth;

use RuntimeException;

/**
 * Thrown when a login attempt is made against a locked account.
 *
 * The lockout window is communicated via getLockedUntil() so that the
 * exception renderer can include it in the 423 response body.
 */
class AccountLockedException extends RuntimeException
{
    public function __construct(
        private readonly ?\DateTimeImmutable $lockedUntil = null,
        string $message = 'Account is temporarily locked due to repeated failed login attempts.',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The datetime until which the account is locked.
     * Null if the lockout timestamp could not be determined.
     */
    public function getLockedUntil(): ?\DateTimeImmutable
    {
        return $this->lockedUntil;
    }
}
