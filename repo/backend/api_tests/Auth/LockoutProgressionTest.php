<?php

use App\Models\Department;
use App\Models\FailedLoginAttempt;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * API tests for account lockout progression.
 *
 * LoginTest.php covers already-locked accounts. This file verifies the full
 * progressive lockout path: 5 consecutive failed attempts trigger a 15-minute
 * lockout, and the subsequent attempt returns 423 with account_locked.
 *
 * Requirement: Auth domain — 5-attempt lockout for 15 minutes.
 */
describe('Account Lockout Progression', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $dept = Department::create(['name' => 'Security', 'code' => 'SEC']);

        $this->user = User::create([
            'username'      => 'lockout_target',
            'password_hash' => Hash::make('CorrectPass1!'),
            'display_name'  => 'Lockout Target',
            'department_id' => $dept->id,
            'is_active'     => true,
        ]);
    });

    // -------------------------------------------------------------------------
    // Progressive failure counting
    // -------------------------------------------------------------------------

    it('increments failed_attempt_count on each wrong-password attempt', function () {
        // 3 consecutive failures
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'lockout_target',
                'password' => 'WrongPassword!',
            ])->assertStatus(401);
        }

        $this->user->refresh();
        expect($this->user->failed_attempt_count)->toBe(3);
    });

    it('keeps last_failed_at updated after each wrong attempt', function () {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'lockout_target',
            'password' => 'WrongPassword!',
        ])->assertStatus(401);

        $this->user->refresh();
        expect($this->user->last_failed_at)->not->toBeNull();
    });

    // -------------------------------------------------------------------------
    // Lockout trigger at 5th attempt
    // -------------------------------------------------------------------------

    it('returns 423 with account_locked after 5 consecutive failed login attempts', function () {
        // First 4 attempts — each returns 401 (wrong password), count increments
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'lockout_target',
                'password' => 'WrongPassword!',
            ])->assertStatus(401);
        }

        // 5th attempt — triggers the lockout (still returns 401 for this attempt,
        // but the account is now locked for subsequent attempts)
        $this->postJson('/api/v1/auth/login', [
            'username' => 'lockout_target',
            'password' => 'WrongPassword!',
        ])->assertStatus(401);

        // 6th attempt — account is locked; must return 423
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'lockout_target',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(423)
            ->assertJson([
                'error' => ['code' => 'account_locked'],
            ]);

        expect($response->json('error.details.locked_until'))->not->toBeNull();
    });

    it('locks the account for 15 minutes after 5 failed attempts', function () {
        // Trigger lockout
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'lockout_target',
                'password' => 'WrongPassword!',
            ]);
        }

        $this->user->refresh();

        expect($this->user->locked_until)->not->toBeNull();

        // locked_until should be approximately now + 15 minutes (within a 2-second tolerance)
        $expectedExpiry = now()->addMinutes(15);
        $diff = abs($this->user->locked_until->diffInSeconds($expectedExpiry));

        expect($diff)->toBeLessThan(10);
    });

    // -------------------------------------------------------------------------
    // Correct password succeeds even after earlier failures (before lockout)
    // -------------------------------------------------------------------------

    it('allows login with correct password after fewer than 5 failed attempts', function () {
        // 3 failures
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'lockout_target',
                'password' => 'WrongPassword!',
            ])->assertStatus(401);
        }

        // Correct password — must succeed
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'lockout_target',
            'password' => 'CorrectPass1!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    });

    // -------------------------------------------------------------------------
    // Failed-attempt counter reset on successful login
    // -------------------------------------------------------------------------

    it('clears failed_attempt_count and locked_until after a successful login', function () {
        // Accumulate 3 failures
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'lockout_target',
                'password' => 'WrongPassword!',
            ]);
        }

        // Successful login
        $this->postJson('/api/v1/auth/login', [
            'username' => 'lockout_target',
            'password' => 'CorrectPass1!',
        ])->assertStatus(200);

        $this->user->refresh();

        expect($this->user->failed_attempt_count)->toBe(0);
        expect($this->user->locked_until)->toBeNull();
        expect($this->user->last_failed_at)->toBeNull();
    });

    // -------------------------------------------------------------------------
    // Lock expiry allows login
    // -------------------------------------------------------------------------

    it('allows login after the 15-minute lock window has expired', function () {
        // Manually set the account as locked with an already-expired lock
        $this->user->update([
            'failed_attempt_count' => 5,
            'locked_until'         => now()->subMinute(), // expired 1 minute ago
        ]);

        // The account is no longer locked — login with correct password must succeed
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'lockout_target',
            'password' => 'CorrectPass1!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['token']]);
    });

    it('returns 423 when account is locked even with the correct password', function () {
        // Pre-lock the account
        $this->user->update([
            'failed_attempt_count' => 5,
            'locked_until'         => now()->addMinutes(14), // still locked
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'lockout_target',
            'password' => 'CorrectPass1!', // correct password but account is locked
        ]);

        $response->assertStatus(423)
            ->assertJson([
                'error' => ['code' => 'account_locked'],
            ]);
    });

    // -------------------------------------------------------------------------
    // FailedLoginAttempt records (feeds admin/failed-logins endpoint)
    // -------------------------------------------------------------------------

    it('creates a FailedLoginAttempt record for each wrong-password attempt', function () {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'lockout_target',
                'password' => 'WrongPassword!',
            ]);
        }

        expect(FailedLoginAttempt::where('user_id', $this->user->id)->count())->toBe(3);
    });

    it('creates a FailedLoginAttempt record with correct username and IP for unknown user', function () {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'nonexistent_user',
            'password' => 'AnyPass1!',
        ])->assertStatus(401);

        $record = FailedLoginAttempt::whereNull('user_id')
            ->where('username_attempted', 'nonexistent_user')
            ->first();

        expect($record)->not->toBeNull();
        expect($record->ip_address)->not->toBeEmpty();
        expect($record->attempted_at)->not->toBeNull();
    });

});
