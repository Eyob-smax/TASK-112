<?php

use App\Domain\Attachment\ValueObjects\LinkTtlConstraint;

describe('LinkTtlConstraint', function () {

    describe('constants', function () {

        it('enforces MAX_TTL_HOURS of 72', function () {
            expect(LinkTtlConstraint::MAX_TTL_HOURS)->toBe(72);
        });

        it('enforces DEFAULT_VALIDITY_DAYS of 365', function () {
            expect(LinkTtlConstraint::DEFAULT_VALIDITY_DAYS)->toBe(365);
        });

    });

    describe('isTtlAllowed()', function () {

        it('allows TTL of 1 hour (minimum)', function () {
            expect(LinkTtlConstraint::isTtlAllowed(1))->toBeTrue();
        });

        it('allows TTL of exactly 72 hours (maximum)', function () {
            expect(LinkTtlConstraint::isTtlAllowed(72))->toBeTrue();
        });

        it('allows TTL within range', function () {
            expect(LinkTtlConstraint::isTtlAllowed(24))->toBeTrue();
            expect(LinkTtlConstraint::isTtlAllowed(48))->toBeTrue();
            expect(LinkTtlConstraint::isTtlAllowed(71))->toBeTrue();
        });

        it('rejects TTL of 0 hours', function () {
            expect(LinkTtlConstraint::isTtlAllowed(0))->toBeFalse();
        });

        it('rejects TTL exceeding 72 hours', function () {
            expect(LinkTtlConstraint::isTtlAllowed(73))->toBeFalse();
            expect(LinkTtlConstraint::isTtlAllowed(168))->toBeFalse(); // 7 days
        });

    });

    describe('clampTtl()', function () {

        it('clamps TTL above 72 to 72', function () {
            expect(LinkTtlConstraint::clampTtl(100))->toBe(72);
        });

        it('clamps TTL of 0 to 1', function () {
            expect(LinkTtlConstraint::clampTtl(0))->toBe(1);
        });

        it('leaves valid TTL unchanged', function () {
            expect(LinkTtlConstraint::clampTtl(24))->toBe(24);
        });

    });

    describe('computeExpiry()', function () {

        it('computes expiry as now plus TTL hours', function () {
            $now = new DateTimeImmutable('2024-01-15 10:00:00');
            $expiry = LinkTtlConstraint::computeExpiry($now, 24);

            expect($expiry->format('Y-m-d H:i:s'))->toBe('2024-01-16 10:00:00');
        });

        it('computes expiry for 72 hours correctly', function () {
            $now = new DateTimeImmutable('2024-01-15 10:00:00');
            $expiry = LinkTtlConstraint::computeExpiry($now, 72);

            expect($expiry->format('Y-m-d H:i:s'))->toBe('2024-01-18 10:00:00');
        });

    });

    describe('isExpired()', function () {

        it('returns true when expiry has passed', function () {
            $expiresAt = new DateTimeImmutable('2024-01-15 09:00:00');
            $now = new DateTimeImmutable('2024-01-15 10:00:00');

            expect(LinkTtlConstraint::isExpired($expiresAt, $now))->toBeTrue();
        });

        it('returns true at the exact expiry moment', function () {
            $expiresAt = new DateTimeImmutable('2024-01-15 10:00:00');
            $now = new DateTimeImmutable('2024-01-15 10:00:00');

            expect(LinkTtlConstraint::isExpired($expiresAt, $now))->toBeTrue();
        });

        it('returns false when expiry has not yet passed', function () {
            $expiresAt = new DateTimeImmutable('2024-01-15 11:00:00');
            $now = new DateTimeImmutable('2024-01-15 10:00:00');

            expect(LinkTtlConstraint::isExpired($expiresAt, $now))->toBeFalse();
        });

    });

});
