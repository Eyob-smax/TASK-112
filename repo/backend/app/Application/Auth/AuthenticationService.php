<?php

namespace App\Application\Auth;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Auth\Contracts\UserRepositoryInterface;
use App\Domain\Auth\ValueObjects\LockoutPolicy;
use App\Exceptions\Auth\AccountLockedException;
use App\Models\FailedLoginAttempt;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Orchestrates username/password authentication with lockout enforcement.
 *
 * Responsibilities:
 *   - Locate user by username (case-insensitive)
 *   - Check account status (active / locked)
 *   - Verify password using bcrypt (Hash::check against password_hash column)
 *   - Track failed attempts; enforce 5-attempt → 15-minute lockout
 *   - Issue Sanctum bearer token on success
 *   - Write an audit event for every outcome (login, login_failed, lockout, logout)
 */
class AuthenticationService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * Attempt a login.
     *
     * @return array{token: string, user: User}
     *
     * @throws AuthenticationException  On bad credentials or inactive account
     * @throws AccountLockedException   When the account is within a lockout window
     */
    public function login(string $username, string $password, string $ipAddress): array
    {
        $user = $this->users->findByUsername($username);

        // Unknown username
        if ($user === null) {
            $this->recordFailedAttempt(userId: null, usernameAttempted: $username, ip: $ipAddress);
            $this->recordAudit(
                action: AuditAction::LoginFailed,
                actorId: null,
                payload: ['username_attempted' => $username, 'reason' => 'user_not_found'],
                ip: $ipAddress,
            );
            throw new AuthenticationException('Invalid credentials.');
        }

        // Inactive account — treated as unknown to avoid enumeration
        if (!$user->is_active) {
            $this->recordFailedAttempt(userId: $user->id, usernameAttempted: $username, ip: $ipAddress);
            $this->recordAudit(
                action: AuditAction::LoginFailed,
                actorId: $user->id,
                payload: ['reason' => 'account_inactive'],
                ip: $ipAddress,
            );
            throw new AuthenticationException('Invalid credentials.');
        }

        // Locked account
        if ($this->users->isLocked($user->id)) {
            $lockedUntil = $user->locked_until?->toDateTimeImmutable();
            $this->recordFailedAttempt(userId: $user->id, usernameAttempted: $username, ip: $ipAddress);
            $this->recordAudit(
                action: AuditAction::LoginFailed,
                actorId: $user->id,
                payload: ['reason' => 'account_locked', 'locked_until' => $lockedUntil?->format(\DateTimeInterface::ATOM)],
                ip: $ipAddress,
            );
            throw new AccountLockedException($lockedUntil);
        }

        // Wrong password
        if (!Hash::check($password, $user->password_hash)) {
            $this->recordFailedAttempt(userId: $user->id, usernameAttempted: $username, ip: $ipAddress);
            $this->users->incrementFailedAttempts($user->id);

            // Reload to get the updated count
            $user->refresh();

            if (LockoutPolicy::shouldLock($user->failed_attempt_count)) {
                $lockUntil  = LockoutPolicy::lockoutUntil(new \DateTimeImmutable());
                $beforeHash = hash('sha256', json_encode($user->toArray())); // state before lock
                $this->users->lockUntil($user->id, $lockUntil);
                $afterHash  = hash('sha256', json_encode($user->fresh()->toArray())); // state after lock

                $this->recordAudit(
                    action: AuditAction::Lockout,
                    actorId: $user->id,
                    payload: [
                        'reason'       => 'max_attempts_exceeded',
                        'attempts'     => $user->failed_attempt_count,
                        'locked_until' => $lockUntil->format(\DateTimeInterface::ATOM),
                    ],
                    ip: $ipAddress,
                    beforeHash: $beforeHash,
                    afterHash: $afterHash,
                );
            } else {
                $this->recordAudit(
                    action: AuditAction::LoginFailed,
                    actorId: $user->id,
                    payload: [
                        'reason'   => 'wrong_password',
                        'attempts' => $user->failed_attempt_count,
                    ],
                    ip: $ipAddress,
                );
            }

            throw new AuthenticationException('Invalid credentials.');
        }

        // Success — clear failed attempts and issue token
        $this->users->clearFailedAttempts($user->id);
        $user->refresh();

        $token = $user->createToken('api')->plainTextToken;

        $this->recordAudit(
            action: AuditAction::Login,
            actorId: $user->id,
            payload: [],
            ip: $ipAddress,
        );

        return [
            'token' => $token,
            'user'  => $user->load('department'),
        ];
    }

    /**
     * Revoke the current access token.
     */
    public function logout(User $user, string $ipAddress): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        } else {
            $this->revokeBearerTokenFallback($user);
        }

        // RequestGuard instances can keep an in-memory user reference in feature tests.
        // Clear it so subsequent requests must re-authenticate against the token store.
        auth('sanctum')->forgetUser();

        $this->recordAudit(
            action: AuditAction::Logout,
            actorId: $user->id,
            payload: [],
            ip: $ipAddress,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Persist a failed login attempt record for admin inspection.
     * Called for every authentication failure regardless of reason.
     */
    private function recordFailedAttempt(?string $userId, string $usernameAttempted, string $ip): void
    {
        FailedLoginAttempt::create([
            'user_id'            => $userId,
            'username_attempted' => $usernameAttempted,
            'ip_address'         => $ip,
            'attempted_at'       => now(),
        ]);
    }

    private function recordAudit(
        AuditAction $action,
        ?string $actorId,
        array $payload,
        string $ip,
        ?string $beforeHash = null,
        ?string $afterHash = null,
    ): void {
        // Auth events have no specific auditable model; propagate idempotency key for request linkage
        $idempotencyKey = request()->header('X-Idempotency-Key');
        $correlationId  = $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey . ':auth:' . $action->value)
            : (string) Str::uuid();

        $this->audit->record(
            correlationId:  $correlationId,
            action:         $action,
            actorId:        $actorId,
            auditableType:  null,
            auditableId:    null,
            beforeHash:     $beforeHash,
            afterHash:      $afterHash,
            payload:        $payload,
            ipAddress:      $ip,
        );
    }

    private function revokeBearerTokenFallback(User $user): void
    {
        $bearer = request()->bearerToken();

        if (!is_string($bearer) || $bearer === '') {
            return;
        }

        if (str_contains($bearer, '|')) {
            [$id] = explode('|', $bearer, 2);
            if (ctype_digit($id)) {
                $user->tokens()->whereKey((int) $id)->delete();
                return;
            }
        }

        $user->tokens()->where('token', hash('sha256', $bearer))->delete();
    }
}
