<?php

use App\Infrastructure\Security\ExpiryEvaluator;
use App\Models\Attachment;
use App\Models\AttachmentLink;

/**
 * Unit tests for ExpiryEvaluator.
 *
 * No mocks needed — ExpiryEvaluator has no constructor dependencies.
 * Attachment and AttachmentLink are used as plain property bags.
 */
describe('ExpiryEvaluator', function () {

    beforeEach(function () {
        $this->evaluator = new ExpiryEvaluator();
    });

    // -------------------------------------------------------------------------
    // Attachment expiry
    // -------------------------------------------------------------------------

    it('returns false for attachment with null expires_at — never expires', function () {
        $attachment = new Attachment();
        $attachment->expires_at = null;

        expect($this->evaluator->isAttachmentExpired($attachment))->toBeFalse();
    });

    it('returns true for attachment with past expires_at', function () {
        $attachment = new Attachment();
        $attachment->expires_at = now()->subDay();

        expect($this->evaluator->isAttachmentExpired($attachment))->toBeTrue();
    });

    it('returns false for attachment with future expires_at', function () {
        $attachment = new Attachment();
        $attachment->expires_at = now()->addDay();

        expect($this->evaluator->isAttachmentExpired($attachment))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Link expiry
    // -------------------------------------------------------------------------

    it('isLinkExpired returns true for link with past expires_at', function () {
        $link = new AttachmentLink();
        $link->expires_at = now()->subHour();

        expect($this->evaluator->isLinkExpired($link))->toBeTrue();
    });

    it('isLinkExpired returns false for link with future expires_at', function () {
        $link = new AttachmentLink();
        $link->expires_at = now()->addHour();

        expect($this->evaluator->isLinkExpired($link))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Link consumption
    // -------------------------------------------------------------------------

    it('isLinkConsumed returns true for single-use link with consumed_at set', function () {
        $link = new AttachmentLink();
        $link->is_single_use = true;
        $link->consumed_at   = now()->subMinutes(5);

        expect($this->evaluator->isLinkConsumed($link))->toBeTrue();
    });

    it('isLinkConsumed returns false for single-use link not yet consumed', function () {
        $link = new AttachmentLink();
        $link->is_single_use = true;
        $link->consumed_at   = null;

        expect($this->evaluator->isLinkConsumed($link))->toBeFalse();
    });

    it('isLinkConsumed returns false for non-single-use link regardless of consumed_at', function () {
        $link = new AttachmentLink();
        $link->is_single_use = false;
        $link->consumed_at   = now()->subMinutes(5); // set but doesn't matter

        expect($this->evaluator->isLinkConsumed($link))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Link revocation
    // -------------------------------------------------------------------------

    it('isLinkRevoked returns true when revoked_at is set', function () {
        $link = new AttachmentLink();
        $link->revoked_at = now()->subMinutes(10);

        expect($this->evaluator->isLinkRevoked($link))->toBeTrue();
    });

    it('isLinkRevoked returns false when revoked_at is null', function () {
        $link = new AttachmentLink();
        $link->revoked_at = null;

        expect($this->evaluator->isLinkRevoked($link))->toBeFalse();
    });

    // -------------------------------------------------------------------------
    // Link usability (composite)
    // -------------------------------------------------------------------------

    it('isLinkUsable returns false when link is expired', function () {
        $link = new AttachmentLink();
        $link->expires_at    = now()->subHour();
        $link->is_single_use = false;
        $link->consumed_at   = null;
        $link->revoked_at    = null;

        expect($this->evaluator->isLinkUsable($link))->toBeFalse();
    });

    it('isLinkUsable returns false when link is consumed', function () {
        $link = new AttachmentLink();
        $link->expires_at    = now()->addHour();
        $link->is_single_use = true;
        $link->consumed_at   = now()->subMinutes(5);
        $link->revoked_at    = null;

        expect($this->evaluator->isLinkUsable($link))->toBeFalse();
    });

    it('isLinkUsable returns false when link is revoked', function () {
        $link = new AttachmentLink();
        $link->expires_at    = now()->addHour();
        $link->is_single_use = false;
        $link->consumed_at   = null;
        $link->revoked_at    = now()->subMinutes(2);

        expect($this->evaluator->isLinkUsable($link))->toBeFalse();
    });

    it('isLinkUsable returns true when link is active, not consumed, not revoked', function () {
        $link = new AttachmentLink();
        $link->expires_at    = now()->addHour();
        $link->is_single_use = false;
        $link->consumed_at   = null;
        $link->revoked_at    = null;

        expect($this->evaluator->isLinkUsable($link))->toBeTrue();
    });

});
