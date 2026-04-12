<?php

use App\Domain\Configuration\ValueObjects\CanaryConstraint;

describe('CanaryConstraint', function () {

    describe('config-backed defaults', function () {

        it('returns max_canary_percent of 10.0 by default', function () {
            expect(CanaryConstraint::maxCanaryPercent())->toBe(10.0);
        });

        it('returns min_promotion_hours of 24 by default', function () {
            expect(CanaryConstraint::minPromotionHours())->toBe(24);
        });

    });

    describe('maxTargets()', function () {

        it('allows 10 targets when eligible population is 100', function () {
            expect(CanaryConstraint::maxTargets(100))->toBe(10);
        });

        it('allows 1 target when eligible population is 10', function () {
            expect(CanaryConstraint::maxTargets(10))->toBe(1);
        });

        it('allows 0 targets when eligible population is 9 (floor of 0.9)', function () {
            // 10% of 9 = 0.9, floored to 0
            expect(CanaryConstraint::maxTargets(9))->toBe(0);
        });

        it('allows 5 targets when eligible population is 50', function () {
            expect(CanaryConstraint::maxTargets(50))->toBe(5);
        });

        it('allows 0 targets when population is 0', function () {
            expect(CanaryConstraint::maxTargets(0))->toBe(0);
        });

    });

    describe('isWithinCap()', function () {

        it('accepts exactly 10% selection (10 of 100)', function () {
            expect(CanaryConstraint::isWithinCap(10, 100))->toBeTrue();
        });

        it('accepts below 10% selection (5 of 100)', function () {
            expect(CanaryConstraint::isWithinCap(5, 100))->toBeTrue();
        });

        it('rejects selection exceeding 10% (11 of 100)', function () {
            expect(CanaryConstraint::isWithinCap(11, 100))->toBeFalse();
        });

        it('rejects any selection when eligible population is 0', function () {
            expect(CanaryConstraint::isWithinCap(1, 0))->toBeFalse();
        });

        it('rejects selection exceeding floor cap (1 of 9 — would be 11.1%)', function () {
            // maxTargets(9) = 0, so any selection is invalid
            expect(CanaryConstraint::isWithinCap(1, 9))->toBeFalse();
        });

    });

    describe('canPromote()', function () {

        it('allows promotion after 24+ hours', function () {
            $started = new DateTimeImmutable('2024-01-15 10:00:00');
            $now = new DateTimeImmutable('2024-01-16 10:00:01'); // 24h + 1s

            expect(CanaryConstraint::canPromote($started, $now))->toBeTrue();
        });

        it('allows promotion at exactly 24 hours', function () {
            $started = new DateTimeImmutable('2024-01-15 10:00:00');
            $now = new DateTimeImmutable('2024-01-16 10:00:00'); // exactly 24h

            expect(CanaryConstraint::canPromote($started, $now))->toBeTrue();
        });

        it('blocks promotion before 24 hours', function () {
            $started = new DateTimeImmutable('2024-01-15 10:00:00');
            $now = new DateTimeImmutable('2024-01-16 09:59:59'); // 1s short of 24h

            expect(CanaryConstraint::canPromote($started, $now))->toBeFalse();
        });

    });

    describe('earliestPromotionAt()', function () {

        it('returns exactly 24 hours after canary start', function () {
            $started = new DateTimeImmutable('2024-01-15 10:00:00');
            $earliest = CanaryConstraint::earliestPromotionAt($started);

            expect($earliest->format('Y-m-d H:i:s'))->toBe('2024-01-16 10:00:00');
        });

    });

});
