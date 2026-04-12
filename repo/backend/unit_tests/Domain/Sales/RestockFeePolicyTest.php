<?php

use App\Domain\Sales\ValueObjects\RestockFeePolicy;

describe('RestockFeePolicy', function () {

    describe('config-backed defaults', function () {

        it('returns default_restock_percent of 10.0 by default', function () {
            expect(RestockFeePolicy::defaultFeePercent())->toBe(10.0);
        });

        it('returns qualifying_days of 30 by default', function () {
            expect(RestockFeePolicy::qualifyingDays())->toBe(30);
        });

    });

    describe('calculateFee()', function () {

        it('returns 0 fee for defective items regardless of timing', function () {
            expect(RestockFeePolicy::calculateFee(500.00, true, 5))->toBe(0.0);
            expect(RestockFeePolicy::calculateFee(500.00, true, 15))->toBe(0.0);
            expect(RestockFeePolicy::calculateFee(500.00, true, 60))->toBe(0.0); // Beyond window
        });

        it('applies 10% fee for non-defective return within 30 days', function () {
            // 10% of 500 = 50
            expect(RestockFeePolicy::calculateFee(500.00, false, 15))->toBe(50.0);
        });

        it('applies 10% fee for non-defective return at exactly 30 days', function () {
            expect(RestockFeePolicy::calculateFee(200.00, false, 30))->toBe(20.0);
        });

        it('applies 10% fee for non-defective return beyond 30 days', function () {
            // Fee still applies — the qualifying window determines acceptance, not fee waiver
            expect(RestockFeePolicy::calculateFee(300.00, false, 45))->toBe(30.0);
        });

        it('correctly rounds to 2 decimal places', function () {
            // 10% of 333.33 = 33.333 → rounded to 33.33
            expect(RestockFeePolicy::calculateFee(333.33, false, 10))->toBe(33.33);
        });

        it('applies custom fee percentage override', function () {
            // 15% of 500 = 75
            expect(RestockFeePolicy::calculateFee(500.00, false, 10, 15.0))->toBe(75.0);
        });

    });

    describe('isWithinQualifyingWindow()', function () {

        it('returns true for returns within 30 days', function () {
            expect(RestockFeePolicy::isWithinQualifyingWindow(1))->toBeTrue();
            expect(RestockFeePolicy::isWithinQualifyingWindow(29))->toBeTrue();
        });

        it('returns true at exactly 30 days', function () {
            expect(RestockFeePolicy::isWithinQualifyingWindow(30))->toBeTrue();
        });

        it('returns false for returns beyond 30 days', function () {
            expect(RestockFeePolicy::isWithinQualifyingWindow(31))->toBeFalse();
            expect(RestockFeePolicy::isWithinQualifyingWindow(90))->toBeFalse();
        });

    });

    describe('calculateRefundAmount()', function () {

        it('subtracts restock fee from return amount', function () {
            expect(RestockFeePolicy::calculateRefundAmount(500.00, 50.00))->toBe(450.0);
        });

        it('returns 0 when restock fee equals or exceeds return amount', function () {
            expect(RestockFeePolicy::calculateRefundAmount(50.00, 50.00))->toBe(0.0);
            expect(RestockFeePolicy::calculateRefundAmount(50.00, 60.00))->toBe(0.0);
        });

        it('returns full amount when restock fee is 0', function () {
            expect(RestockFeePolicy::calculateRefundAmount(500.00, 0.0))->toBe(500.0);
        });

    });

});
