<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Auth\Contracts\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EloquentUserRepository implements UserRepositoryInterface
{
    /**
     * Find a user by username (case-insensitive).
     */
    public function findByUsername(string $username): mixed
    {
        return User::whereRaw('LOWER(username) = LOWER(?)', [$username])->first();
    }

    /**
     * Find a user by UUID.
     */
    public function findById(string $id): mixed
    {
        return User::find($id);
    }

    /**
     * Increment the failed login attempt count and record the timestamp.
     */
    public function incrementFailedAttempts(string $userId): void
    {
        User::where('id', $userId)->increment('failed_attempt_count', 1, [
            'last_failed_at' => now(),
        ]);
    }

    /**
     * Set the locked_until timestamp.
     *
     * NOTE: This does NOT set is_active = false.
     * Lockout is temporary; deactivation is a separate, permanent action.
     */
    public function lockUntil(string $userId, \DateTimeImmutable $until): void
    {
        User::where('id', $userId)->update([
            'locked_until' => $until,
        ]);
    }

    /**
     * Reset the failed attempt counter and remove the lockout timestamp.
     */
    public function clearFailedAttempts(string $userId): void
    {
        User::where('id', $userId)->update([
            'failed_attempt_count' => 0,
            'locked_until'         => null,
            'last_failed_at'       => null,
        ]);
    }

    /**
     * Whether the user account is currently within a lockout window.
     */
    public function isLocked(string $userId): bool
    {
        return User::where('id', $userId)
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->exists();
    }
}
