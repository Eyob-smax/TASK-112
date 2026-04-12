<?php

namespace App\Domain\Auth\Contracts;

/**
 * Contract for user persistence operations.
 *
 * Implementation: App\Infrastructure\Persistence\EloquentUserRepository (Prompt 3+)
 */
interface UserRepositoryInterface
{
    /**
     * Find a user by their username (case-insensitive).
     *
     * @return mixed|null Null if not found
     */
    public function findByUsername(string $username): mixed;

    /**
     * Find a user by UUID.
     */
    public function findById(string $id): mixed;

    /**
     * Increment the failed login attempt count for a user.
     * Also records the timestamp of the failure.
     */
    public function incrementFailedAttempts(string $userId): void;

    /**
     * Lock a user account until the given datetime.
     */
    public function lockUntil(string $userId, \DateTimeImmutable $until): void;

    /**
     * Reset the failed login attempt count and clear any lockout.
     */
    public function clearFailedAttempts(string $userId): void;

    /**
     * Whether the given user account is currently locked.
     */
    public function isLocked(string $userId): bool;
}
