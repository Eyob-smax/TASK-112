<?php

use App\Domain\Auth\ValueObjects\PasswordPolicy;

describe('PasswordPolicy', function () {

    describe('validate()', function () {

        it('accepts a valid password meeting all requirements', function () {
            expect(PasswordPolicy::validate('SecurePass1!'))->toBeTrue();
            expect(PasswordPolicy::validate('Admin12345678'))->toBeTrue();
            expect(PasswordPolicy::validate('Meridian2024_Enterprise'))->toBeTrue();
        });

        it('rejects a password shorter than 12 characters', function () {
            expect(PasswordPolicy::validate('Short1A'))->toBeFalse();
            expect(PasswordPolicy::validate('Ab1defghijk'))->toBeFalse(); // 11 chars
        });

        it('rejects a password with exactly 12 chars but no uppercase', function () {
            expect(PasswordPolicy::validate('lowercase1234'))->toBeFalse();
        });

        it('rejects a password with exactly 12 chars but no lowercase', function () {
            expect(PasswordPolicy::validate('UPPERCASE1234'))->toBeFalse();
        });

        it('rejects a password with exactly 12 chars but no digit', function () {
            expect(PasswordPolicy::validate('NoDigitHereAB'))->toBeFalse();
        });

        it('accepts a password of exactly 12 characters with all required types', function () {
            expect(PasswordPolicy::validate('Meridian2024'))->toBeTrue(); // exactly 12 chars
        });

    });

    describe('violations()', function () {

        it('returns empty array for a valid password', function () {
            $violations = PasswordPolicy::violations('SecurePass123');
            expect($violations)->toBeEmpty();
        });

        it('reports all violations simultaneously for a weak password', function () {
            $violations = PasswordPolicy::violations('short'); // Too short, no upper, no digit

            expect($violations)->toHaveCount(3)
                ->and($violations[0])->toContain('12 characters')
                ->and($violations[1])->toContain('uppercase')
                ->and($violations[2])->toContain('digit');
        });

        it('reports only the length violation when the rest are satisfied', function () {
            $violations = PasswordPolicy::violations('Short1A'); // Too short, but has upper, lower, digit

            expect($violations)->toHaveCount(1)
                ->and($violations[0])->toContain('12 characters');
        });

        it('reports only the uppercase violation', function () {
            $violations = PasswordPolicy::violations('nouppercase1234');
            expect($violations)->toHaveCount(1)
                ->and($violations[0])->toContain('uppercase');
        });

        it('reports only the digit violation', function () {
            $violations = PasswordPolicy::violations('NoDigitPasswordHere');
            expect($violations)->toHaveCount(1)
                ->and($violations[0])->toContain('digit');
        });

    });

    describe('constants', function () {

        it('enforces minimum length of 12', function () {
            expect(PasswordPolicy::MIN_LENGTH)->toBe(12);
        });

    });

});
