<?php

use App\Domain\Auth\ValueObjects\LockoutPolicy;

describe('LockoutPolicy', function () {

    describe('config-backed defaults', function () {

        it('returns max_attempts of 5 by default', function () {
            expect(LockoutPolicy::maxAttempts())->toBe(5);
        });

        it('returns lockout_minutes of 15 by default', function () {
            expect(LockoutPolicy::lockoutMinutes())->toBe(15);
        });

    });

    describe('shouldLock()', function () {

        it('does not lock before reaching the threshold', function () {
            expect(LockoutPolicy::shouldLock(0))->toBeFalse();
            expect(LockoutPolicy::shouldLock(3))->toBeFalse();
            expect(LockoutPolicy::shouldLock(4))->toBeFalse();
        });

        it('locks at exactly the configured threshold (5 by default)', function () {
            expect(LockoutPolicy::shouldLock(5))->toBeTrue();
        });

        it('locks when attempts exceed the threshold', function () {
            expect(LockoutPolicy::shouldLock(6))->toBeTrue();
            expect(LockoutPolicy::shouldLock(100))->toBeTrue();
        });

    });

    describe('lockoutUntil()', function () {

        it('computes expiry exactly 15 minutes from now', function () {
            $now = new DateTimeImmutable('2024-01-15 10:00:00');
            $lockedUntil = LockoutPolicy::lockoutUntil($now);

            expect($lockedUntil->format('Y-m-d H:i:s'))->toBe('2024-01-15 10:15:00');
        });

    });

    describe('isLockoutExpired()', function () {

        it('returns true when current time is after lockout expiry', function () {
            $lockedUntil = new DateTimeImmutable('2024-01-15 10:15:00');
            $now = new DateTimeImmutable('2024-01-15 10:16:00');

            expect(LockoutPolicy::isLockoutExpired($lockedUntil, $now))->toBeTrue();
        });

        it('returns false when current time is before lockout expiry', function () {
            $lockedUntil = new DateTimeImmutable('2024-01-15 10:15:00');
            $now = new DateTimeImmutable('2024-01-15 10:10:00');

            expect(LockoutPolicy::isLockoutExpired($lockedUntil, $now))->toBeFalse();
        });

        it('returns false at the exact expiry moment (not expired yet)', function () {
            $lockedUntil = new DateTimeImmutable('2024-01-15 10:15:00');
            $now = new DateTimeImmutable('2024-01-15 10:15:00');

            expect(LockoutPolicy::isLockoutExpired($lockedUntil, $now))->toBeFalse();
        });

    });

});
