<?php

namespace App\Domain\Attachment\ValueObjects;

/**
 * Defines the TTL constraints for LAN attachment share links.
 *
 * Rules (from original prompt):
 *   - Maximum TTL: 72 hours (hard cap, never exceeded)
 *   - Default evidence validity: 365 days
 *   - Single-use option available
 *
 * Immutable value object — no instances.
 */
final class LinkTtlConstraint
{
    /** Hard maximum TTL for LAN share links in hours */
    public const MAX_TTL_HOURS = 72;

    /** Default attachment validity period in days */
    public const DEFAULT_VALIDITY_DAYS = 365;

    private function __construct() {}

    /**
     * Whether the requested TTL in hours is within the allowed limit.
     */
    public static function isTtlAllowed(int $ttlHours): bool
    {
        return $ttlHours >= 1 && $ttlHours <= self::MAX_TTL_HOURS;
    }

    /**
     * Clamp a requested TTL to the maximum allowed value.
     * Use this defensively when the input is not yet validated.
     */
    public static function clampTtl(int $ttlHours): int
    {
        return min(max(1, $ttlHours), self::MAX_TTL_HOURS);
    }

    /**
     * Compute the expiry timestamp for a link given a TTL in hours.
     */
    public static function computeExpiry(\DateTimeImmutable $now, int $ttlHours): \DateTimeImmutable
    {
        return $now->modify("+{$ttlHours} hours");
    }

    /**
     * Whether a given expiry timestamp has passed.
     */
    public static function isExpired(\DateTimeImmutable $expiresAt, \DateTimeImmutable $now): bool
    {
        return $now >= $expiresAt;
    }

    /**
     * Compute the attachment expiry timestamp from its upload date.
     */
    public static function computeAttachmentExpiry(
        \DateTimeImmutable $uploadedAt,
        int $validityDays = self::DEFAULT_VALIDITY_DAYS
    ): \DateTimeImmutable {
        return $uploadedAt->modify("+{$validityDays} days");
    }
}
