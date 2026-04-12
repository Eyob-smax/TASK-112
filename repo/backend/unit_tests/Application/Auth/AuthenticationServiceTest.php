<?php

use App\Application\Auth\AuthenticationService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Auth\Contracts\UserRepositoryInterface;
use App\Domain\Auth\ValueObjects\LockoutPolicy;
use App\Exceptions\Auth\AccountLockedException;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;

/**
 * Unit tests for AuthenticationService.
 *
 * All repository dependencies are mocked — no database required.
 */
describe('AuthenticationService', function () {

    beforeEach(function () {
        $this->userRepo  = Mockery::mock(UserRepositoryInterface::class);
        $this->auditRepo = Mockery::mock(AuditEventRepositoryInterface::class);

        // Allow any audit record call by default
        $this->auditRepo->shouldReceive('record')->andReturn(null)->byDefault();

        $this->service = new AuthenticationService($this->userRepo, $this->auditRepo);
    });

    afterEach(function () {
        Mockery::close();
    });

    it('returns a token and user on valid credentials', function () {
        $plainPassword = 'ValidPass1!';
        $user = Mockery::mock(User::class)->makePartial();
        $user->id           = 'test-user-id';
        $user->username     = 'jdoe';
        $user->password_hash = Hash::make($plainPassword);
        $user->is_active    = true;
        $user->failed_attempt_count = 0;

        $user->shouldReceive('createToken')->andReturn((object)['plainTextToken' => 'sanctum-token']);
        $user->shouldReceive('load')->andReturn($user);
        $user->shouldReceive('refresh')->andReturn($user);

        $this->userRepo->shouldReceive('findByUsername')->with('jdoe')->andReturn($user);
        $this->userRepo->shouldReceive('isLocked')->with('test-user-id')->andReturn(false);
        $this->userRepo->shouldReceive('clearFailedAttempts')->with('test-user-id')->once();

        $result = $this->service->login('jdoe', $plainPassword, '127.0.0.1');

        expect($result)->toHaveKeys(['token', 'user']);
        expect($result['token'])->toBe('sanctum-token');
    });

    it('throws AuthenticationException when user is not found', function () {
        $this->userRepo->shouldReceive('findByUsername')->andReturn(null);

        expect(fn () => $this->service->login('nobody', 'password', '127.0.0.1'))
            ->toThrow(AuthenticationException::class);
    });

    it('throws AuthenticationException when the account is inactive', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id        = 'uid';
        $user->is_active = false;

        $this->userRepo->shouldReceive('findByUsername')->andReturn($user);

        expect(fn () => $this->service->login('jdoe', 'password', '127.0.0.1'))
            ->toThrow(AuthenticationException::class);
    });

    it('throws AccountLockedException when the account is within a lockout window', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id        = 'uid';
        $user->is_active = true;
        $user->locked_until = now()->addMinutes(10)->toImmutable();

        $this->userRepo->shouldReceive('findByUsername')->andReturn($user);
        $this->userRepo->shouldReceive('isLocked')->with('uid')->andReturn(true);

        expect(fn () => $this->service->login('jdoe', 'password', '127.0.0.1'))
            ->toThrow(AccountLockedException::class);
    });

    it('increments failed attempts and throws on wrong password', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id           = 'uid';
        $user->is_active    = true;
        $user->password_hash = Hash::make('correct');
        $user->failed_attempt_count = 1;

        $this->userRepo->shouldReceive('findByUsername')->andReturn($user);
        $this->userRepo->shouldReceive('isLocked')->andReturn(false);
        $this->userRepo->shouldReceive('incrementFailedAttempts')->with('uid')->once();

        $user->shouldReceive('refresh')->andReturn($user);

        expect(fn () => $this->service->login('jdoe', 'wrong', '127.0.0.1'))
            ->toThrow(AuthenticationException::class);
    });

    it('triggers lockout after reaching MAX_ATTEMPTS failed attempts', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id           = 'uid';
        $user->is_active    = true;
        $user->password_hash = Hash::make('correct');

        // After increment, count equals the lockout threshold
        $user->failed_attempt_count = LockoutPolicy::maxAttempts();

        $this->userRepo->shouldReceive('findByUsername')->andReturn($user);
        $this->userRepo->shouldReceive('isLocked')->andReturn(false);
        $this->userRepo->shouldReceive('incrementFailedAttempts')->with('uid')->once();
        $this->userRepo->shouldReceive('lockUntil')->with('uid', Mockery::type(\DateTimeImmutable::class))->once();

        $user->shouldReceive('refresh')->andReturn($user);

        expect(fn () => $this->service->login('jdoe', 'wrong', '127.0.0.1'))
            ->toThrow(AuthenticationException::class);
    });

    it('clears failed attempts on successful login', function () {
        $plainPassword = 'ValidPass1!';
        $user = Mockery::mock(User::class)->makePartial();
        $user->id           = 'uid';
        $user->is_active    = true;
        $user->password_hash = Hash::make($plainPassword);
        $user->failed_attempt_count = 3;

        $user->shouldReceive('createToken')->andReturn((object)['plainTextToken' => 't']);
        $user->shouldReceive('load')->andReturn($user);
        $user->shouldReceive('refresh')->andReturn($user);

        $this->userRepo->shouldReceive('findByUsername')->andReturn($user);
        $this->userRepo->shouldReceive('isLocked')->andReturn(false);
        $this->userRepo->shouldReceive('clearFailedAttempts')->with('uid')->once();

        $this->service->login('jdoe', $plainPassword, '127.0.0.1');

        // Mockery verifies clearFailedAttempts was called once
        expect(true)->toBeTrue();
    });

});
