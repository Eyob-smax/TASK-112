<?php

use App\Domain\Workflow\ValueObjects\SlaDefaults;

describe('SlaDefaults', function () {

    describe('constants', function () {

        it('enforces DEFAULT_SLA_BUSINESS_DAYS of 2', function () {
            expect(SlaDefaults::DEFAULT_SLA_BUSINESS_DAYS)->toBe(2);
        });

    });

    describe('calculateDueAt()', function () {

        it('adds 2 business days from Monday — result is Wednesday', function () {
            $monday = new DateTimeImmutable('2024-01-15 09:00:00'); // Monday
            $due = SlaDefaults::calculateDueAt($monday, 2);

            expect($due->format('D'))->toBe('Wed');
            expect($due->format('Y-m-d'))->toBe('2024-01-17');
        });

        it('adds 2 business days from Friday — skips weekend, result is Tuesday', function () {
            $friday = new DateTimeImmutable('2024-01-19 09:00:00'); // Friday
            $due = SlaDefaults::calculateDueAt($friday, 2);

            expect($due->format('D'))->toBe('Tue');
            expect($due->format('Y-m-d'))->toBe('2024-01-23');
        });

        it('adds 2 business days from Thursday — result is Monday', function () {
            $thursday = new DateTimeImmutable('2024-01-18 09:00:00'); // Thursday
            $due = SlaDefaults::calculateDueAt($thursday, 2);

            expect($due->format('D'))->toBe('Mon');
            expect($due->format('Y-m-d'))->toBe('2024-01-22');
        });

        it('adds 2 business days from Wednesday — result is Friday', function () {
            $wednesday = new DateTimeImmutable('2024-01-17 09:00:00'); // Wednesday
            $due = SlaDefaults::calculateDueAt($wednesday, 2);

            expect($due->format('D'))->toBe('Fri');
            expect($due->format('Y-m-d'))->toBe('2024-01-19');
        });

        it('adds 2 business days from Saturday — skips both Saturday and Sunday', function () {
            // Saturday → next Mon + 1 more = Tuesday
            $saturday = new DateTimeImmutable('2024-01-20 09:00:00'); // Saturday
            $due = SlaDefaults::calculateDueAt($saturday, 2);

            expect($due->format('D'))->toBe('Tue');
            expect($due->format('Y-m-d'))->toBe('2024-01-23');
        });

        it('preserves the time-of-day component', function () {
            $monday = new DateTimeImmutable('2024-01-15 14:30:00');
            $due = SlaDefaults::calculateDueAt($monday, 2);

            expect($due->format('H:i:s'))->toBe('14:30:00');
        });

        it('works with custom SLA of 0 days (same day — no business day added)', function () {
            $monday = new DateTimeImmutable('2024-01-15 09:00:00');
            $due = SlaDefaults::calculateDueAt($monday, 0);

            expect($due->format('Y-m-d'))->toBe('2024-01-15');
        });

    });

    describe('isBusinessDay()', function () {

        it('identifies Monday through Friday as business days', function () {
            expect(SlaDefaults::isBusinessDay(new DateTimeImmutable('2024-01-15')))->toBeTrue(); // Mon
            expect(SlaDefaults::isBusinessDay(new DateTimeImmutable('2024-01-16')))->toBeTrue(); // Tue
            expect(SlaDefaults::isBusinessDay(new DateTimeImmutable('2024-01-17')))->toBeTrue(); // Wed
            expect(SlaDefaults::isBusinessDay(new DateTimeImmutable('2024-01-18')))->toBeTrue(); // Thu
            expect(SlaDefaults::isBusinessDay(new DateTimeImmutable('2024-01-19')))->toBeTrue(); // Fri
        });

        it('identifies Saturday and Sunday as non-business days', function () {
            expect(SlaDefaults::isBusinessDay(new DateTimeImmutable('2024-01-20')))->toBeFalse(); // Sat
            expect(SlaDefaults::isBusinessDay(new DateTimeImmutable('2024-01-21')))->toBeFalse(); // Sun
        });

    });

    describe('isSlaBreached()', function () {

        it('returns true when current time is after the SLA due date', function () {
            $dueAt = new DateTimeImmutable('2024-01-17 09:00:00');
            $now = new DateTimeImmutable('2024-01-17 09:00:01');

            expect(SlaDefaults::isSlaBreached($dueAt, $now))->toBeTrue();
        });

        it('returns false when current time is before the SLA due date', function () {
            $dueAt = new DateTimeImmutable('2024-01-17 09:00:00');
            $now = new DateTimeImmutable('2024-01-16 09:00:00');

            expect(SlaDefaults::isSlaBreached($dueAt, $now))->toBeFalse();
        });

        it('returns false at the exact SLA due moment', function () {
            $dueAt = new DateTimeImmutable('2024-01-17 09:00:00');
            $now = new DateTimeImmutable('2024-01-17 09:00:00');

            expect(SlaDefaults::isSlaBreached($dueAt, $now))->toBeFalse();
        });

    });

});
