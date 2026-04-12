<?php

namespace App\Domain\Auth\ValueObjects;

/**
 * Defines the account lockout policy constants.
 *
 * Rules (from original prompt):
 *   - Lock after 5 consecutive failed login attempts
 *   - Lockout duration: 15 minutes
 *
 * Immutable value object — no instances.
 */
final class LockoutPolicy
{
    private const DEFAULT_MAX_ATTEMPTS    = 5;
    private const DEFAULT_LOCKOUT_MINUTES = 15;

    private function __construct() {}

    /**
     * Maximum consecutive failed attempts before lockout (resolved from config).
     */
    public static function maxAttempts(): int
    {
        return (int) config('meridian.auth.max_failed_attempts', self::DEFAULT_MAX_ATTEMPTS);
    }

    /**
     * Duration in minutes of an account lockout window (resolved from config).
     */
    public static function lockoutMinutes(): int
    {
        return (int) config('meridian.auth.lockout_minutes', self::DEFAULT_LOCKOUT_MINUTES);
    }

    /**
     * Whether the given failed attempt count has reached the lockout threshold.
     */
    public static function shouldLock(int $failedAttemptCount): bool
    {
        return $failedAttemptCount >= self::maxAttempts();
    }

    /**
     * Compute the lockout expiry timestamp from now.
     */
    public static function lockoutUntil(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('+' . self::lockoutMinutes() . ' minutes');
    }

    /**
     * Whether the current lockout window has expired.
     */
    public static function isLockoutExpired(\DateTimeImmutable $lockedUntil, \DateTimeImmutable $now): bool
    {
        return $now > $lockedUntil;
    }
}
