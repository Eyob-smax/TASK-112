<?php

namespace App\Domain\Configuration\ValueObjects;

/**
 * Enforces the canary rollout constraints for configuration versions.
 *
 * Rules (from original prompt):
 *   - Canary rollout capped at 10% of eligible target population
 *   - Must run for 24 hours before full promotion is allowed
 *
 * From questions.md (ambiguity #5):
 *   - Cap applies to the selected target population type (stores OR users, whichever is chosen)
 *
 * Immutable value object — no instances.
 */
final class CanaryConstraint
{
    private const DEFAULT_MAX_CANARY_PERCENT = 10.0;
    private const DEFAULT_MIN_PROMOTION_HOURS = 24;

    private function __construct() {}

    /**
     * Maximum percentage of eligible targets for canary rollout (resolved from config).
     */
    public static function maxCanaryPercent(): float
    {
        return (float) config('meridian.canary.max_percent', self::DEFAULT_MAX_CANARY_PERCENT);
    }

    /**
     * Minimum hours a canary must run before full promotion is allowed (resolved from config).
     */
    public static function minPromotionHours(): int
    {
        return (int) config('meridian.canary.min_promotion_hours', self::DEFAULT_MIN_PROMOTION_HOURS);
    }

    /**
     * Compute the maximum number of targets allowed for a canary rollout.
     *
     * Returns at least 1 when the eligible population is non-zero so that
     * small populations (< 10 stores/users) are not permanently blocked from
     * canary rollout by floor(N * 0.10) == 0.
     *
     * @param int $eligibleCount Total eligible targets in the population
     * @return int Maximum target count (at least 1 for non-empty populations)
     */
    public static function maxTargets(int $eligibleCount): int
    {
        if ($eligibleCount <= 0) {
            return 0;
        }

        return max(1, (int) floor($eligibleCount * (self::maxCanaryPercent() / 100)));
    }

    /**
     * Whether the requested target count is within the 10% cap.
     *
     * @param int $requestedCount Number of targets requested
     * @param int $eligibleCount  Total eligible population
     */
    public static function isWithinCap(int $requestedCount, int $eligibleCount): bool
    {
        if ($eligibleCount <= 0) {
            return false;
        }
        return $requestedCount <= self::maxTargets($eligibleCount);
    }

    /**
     * Compute the actual percentage for the given selection.
     */
    public static function computePercent(int $selectedCount, int $eligibleCount): float
    {
        if ($eligibleCount <= 0) {
            return 0.0;
        }
        return round(($selectedCount / $eligibleCount) * 100, 2);
    }

    /**
     * Whether the canary has been running long enough to allow full promotion.
     */
    public static function canPromote(\DateTimeImmutable $canaryStartedAt, \DateTimeImmutable $now): bool
    {
        $minimumDuration = new \DateInterval('PT' . self::minPromotionHours() . 'H');
        $earliestPromotion = $canaryStartedAt->add($minimumDuration);
        return $now >= $earliestPromotion;
    }

    /**
     * Compute the earliest timestamp at which full promotion is allowed.
     */
    public static function earliestPromotionAt(\DateTimeImmutable $canaryStartedAt): \DateTimeImmutable
    {
        return $canaryStartedAt->modify('+' . self::minPromotionHours() . ' hours');
    }
}
