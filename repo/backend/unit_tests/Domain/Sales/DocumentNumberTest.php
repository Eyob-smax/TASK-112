<?php

use App\Domain\Sales\ValueObjects\DocumentNumberFormat;

describe('DocumentNumberFormat', function () {

    describe('format()', function () {

        it('generates a correctly formatted document number', function () {
            $date = new DateTimeImmutable('2024-01-15');
            $result = DocumentNumberFormat::format('SITE01', $date, 1);

            expect($result)->toBe('SITE01-20240115-000001');
        });

        it('zero-pads sequence to 6 digits', function () {
            $date = new DateTimeImmutable('2024-01-15');
            expect(DocumentNumberFormat::format('HQ', $date, 1))->toEndWith('-000001');
            expect(DocumentNumberFormat::format('HQ', $date, 100))->toEndWith('-000100');
            expect(DocumentNumberFormat::format('HQ', $date, 999999))->toEndWith('-999999');
        });

        it('uppercases the site code', function () {
            $date = new DateTimeImmutable('2024-01-15');
            $result = DocumentNumberFormat::format('site01', $date, 1);

            expect($result)->toStartWith('SITE01-');
        });

    });

    describe('isValid()', function () {

        it('validates a correctly formatted document number', function () {
            expect(DocumentNumberFormat::isValid('SITE01-20240115-000001'))->toBeTrue();
            expect(DocumentNumberFormat::isValid('HQ-20240115-000001'))->toBeTrue();
            expect(DocumentNumberFormat::isValid('WAREHOUSE1-20241231-999999'))->toBeTrue();
        });

        it('rejects document numbers with wrong date format', function () {
            expect(DocumentNumberFormat::isValid('SITE01-2024-01-15-000001'))->toBeFalse();
            expect(DocumentNumberFormat::isValid('SITE01-240115-000001'))->toBeFalse();
        });

        it('rejects document numbers with wrong sequence format', function () {
            expect(DocumentNumberFormat::isValid('SITE01-20240115-1'))->toBeFalse();
            expect(DocumentNumberFormat::isValid('SITE01-20240115-0000001'))->toBeFalse(); // 7 digits
        });

        it('rejects document numbers missing segments', function () {
            expect(DocumentNumberFormat::isValid('SITE01-20240115'))->toBeFalse();
            expect(DocumentNumberFormat::isValid('20240115-000001'))->toBeFalse();
        });

        it('rejects empty string', function () {
            expect(DocumentNumberFormat::isValid(''))->toBeFalse();
        });

    });

    describe('parse()', function () {

        it('parses a valid document number into components', function () {
            $parsed = DocumentNumberFormat::parse('SITE01-20240115-000042');

            expect($parsed)->not->toBeNull()
                ->and($parsed['site_code'])->toBe('SITE01')
                ->and($parsed['date'])->toBe('20240115')
                ->and($parsed['sequence'])->toBe(42);
        });

        it('returns null for an invalid document number', function () {
            expect(DocumentNumberFormat::parse('invalid'))->toBeNull();
            expect(DocumentNumberFormat::parse(''))->toBeNull();
        });

    });

    describe('extractSiteCode()', function () {

        it('extracts the site code from a valid number', function () {
            expect(DocumentNumberFormat::extractSiteCode('SITE01-20240115-000001'))->toBe('SITE01');
        });

        it('returns null for an invalid number', function () {
            expect(DocumentNumberFormat::extractSiteCode('invalid'))->toBeNull();
        });

    });

    describe('extractDate()', function () {

        it('extracts the date from a valid number', function () {
            expect(DocumentNumberFormat::extractDate('SITE01-20240115-000001'))->toBe('20240115');
        });

        it('returns null for an invalid number', function () {
            expect(DocumentNumberFormat::extractDate('invalid'))->toBeNull();
        });

    });

});
