<?php

use App\Domain\Attachment\ValueObjects\FileConstraints;

describe('FileConstraints', function () {

    describe('constants', function () {

        it('enforces MAX_SIZE_BYTES of exactly 25MB', function () {
            expect(FileConstraints::MAX_SIZE_BYTES)->toBe(25 * 1024 * 1024);
            expect(FileConstraints::MAX_SIZE_BYTES)->toBe(26_214_400);
        });

        it('enforces MAX_FILES_PER_RECORD of 20', function () {
            expect(FileConstraints::MAX_FILES_PER_RECORD)->toBe(20);
        });

    });

    describe('isSizeAllowed()', function () {

        it('allows files at exactly 25MB', function () {
            expect(FileConstraints::isSizeAllowed(26_214_400))->toBeTrue();
        });

        it('allows files below 25MB', function () {
            expect(FileConstraints::isSizeAllowed(1_000_000))->toBeTrue();  // 1MB
            expect(FileConstraints::isSizeAllowed(26_214_399))->toBeTrue(); // 1 byte under
        });

        it('rejects files above 25MB', function () {
            expect(FileConstraints::isSizeAllowed(26_214_401))->toBeFalse();
            expect(FileConstraints::isSizeAllowed(50_000_000))->toBeFalse();
        });

        it('rejects zero-byte files', function () {
            expect(FileConstraints::isSizeAllowed(0))->toBeFalse();
        });

    });

    describe('isMimeAllowed()', function () {

        it('allows all 5 required MIME types', function () {
            expect(FileConstraints::isMimeAllowed('application/pdf'))->toBeTrue();
            expect(FileConstraints::isMimeAllowed('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBeTrue();
            expect(FileConstraints::isMimeAllowed('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBeTrue();
            expect(FileConstraints::isMimeAllowed('image/png'))->toBeTrue();
            expect(FileConstraints::isMimeAllowed('image/jpeg'))->toBeTrue();
        });

        it('rejects disallowed MIME types', function () {
            expect(FileConstraints::isMimeAllowed('text/plain'))->toBeFalse();
            expect(FileConstraints::isMimeAllowed('application/zip'))->toBeFalse();
            expect(FileConstraints::isMimeAllowed('application/x-php'))->toBeFalse();
            expect(FileConstraints::isMimeAllowed('image/gif'))->toBeFalse();
            expect(FileConstraints::isMimeAllowed('video/mp4'))->toBeFalse();
        });

    });

    describe('allowedMimeTypes()', function () {

        it('returns exactly 5 allowed MIME types', function () {
            $types = FileConstraints::allowedMimeTypes();
            expect($types)->toHaveCount(5);
        });

        it('includes all required MIME types', function () {
            $types = FileConstraints::allowedMimeTypes();
            expect($types)->toContain('application/pdf');
            expect($types)->toContain('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            expect($types)->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            expect($types)->toContain('image/png');
            expect($types)->toContain('image/jpeg');
        });

    });

    describe('wouldExceedFileCount()', function () {

        it('does not exceed when adding 1 file to 19 existing', function () {
            expect(FileConstraints::wouldExceedFileCount(19, 1))->toBeFalse();
        });

        it('does not exceed when current count is 20 with 0 new files', function () {
            expect(FileConstraints::wouldExceedFileCount(20, 0))->toBeFalse();
        });

        it('exceeds when adding 1 file to 20 existing', function () {
            expect(FileConstraints::wouldExceedFileCount(20, 1))->toBeTrue();
        });

        it('exceeds when adding multiple files that push total over 20', function () {
            expect(FileConstraints::wouldExceedFileCount(18, 3))->toBeTrue();
        });

    });

});
