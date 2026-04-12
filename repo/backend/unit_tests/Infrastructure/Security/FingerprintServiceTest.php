<?php

use App\Infrastructure\Security\FingerprintService;

describe('FingerprintService', function () {

    beforeEach(function () {
        $this->service = new FingerprintService();
    });

    it('produces a deterministic SHA-256 fingerprint for the same content', function () {
        $content = 'identical file content for hashing';

        $hash1 = $this->service->compute($content);
        $hash2 = $this->service->compute($content);

        expect($hash1)->toBe($hash2);
    });

    it('produces different fingerprints for different content', function () {
        $hash1 = $this->service->compute('content A');
        $hash2 = $this->service->compute('content B');

        expect($hash1)->not->toBe($hash2);
    });

    it('fingerprint is exactly 64 hexadecimal characters', function () {
        $hash = $this->service->compute('any content');

        expect(strlen($hash))->toBe(64);
        expect(ctype_xdigit($hash))->toBeTrue();
    });

    it('returns true when verifying content against its own fingerprint', function () {
        $content = 'document to verify';
        $hash    = $this->service->compute($content);

        expect($this->service->verify($content, $hash))->toBeTrue();
    });

    it('returns false when content has been tampered with', function () {
        $original = 'original document';
        $hash     = $this->service->compute($original);

        expect($this->service->verify('tampered document', $hash))->toBeFalse();
    });

    it('returns false for a completely different fingerprint', function () {
        $content  = 'some content';
        $wrongHash = str_repeat('0', 64); // all zeros — invalid SHA-256

        expect($this->service->verify($content, $wrongHash))->toBeFalse();
    });

    it('empty string produces a valid deterministic fingerprint', function () {
        $hash = $this->service->compute('');

        expect(strlen($hash))->toBe(64);
        // SHA-256 of empty string is a known constant
        expect($hash)->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
    });

});
