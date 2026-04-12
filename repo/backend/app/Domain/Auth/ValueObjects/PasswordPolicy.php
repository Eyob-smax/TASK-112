<?php

namespace App\Domain\Auth\ValueObjects;

/**
 * Enforces password complexity rules for the Meridian system.
 *
 * Rules (from original prompt):
 *   - Minimum 12 characters
 *   - At least 1 uppercase letter
 *   - At least 1 lowercase letter
 *   - At least 1 digit
 *
 * This is an immutable value object — no state, static interface only.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    private function __construct() {}

    /**
     * Check whether the given plaintext password satisfies all rules.
     */
    public static function validate(string $password): bool
    {
        return self::violations($password) === [];
    }

    /**
     * Return all violated rule messages for the given password.
     * Empty array means the password is valid.
     *
     * @return list<string>
     */
    public static function violations(string $password): array
    {
        $violations = [];

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $violations[] = 'Password must be at least ' . self::MIN_LENGTH . ' characters long.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $violations[] = 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $violations[] = 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $violations[] = 'Password must contain at least one digit.';
        }

        return $violations;
    }

    /**
     * Return a human-readable description of the password requirements.
     */
    public static function requirements(): string
    {
        return sprintf(
            'Password must be at least %d characters long and contain at least one uppercase letter, one lowercase letter, and one digit.',
            self::MIN_LENGTH
        );
    }
}
