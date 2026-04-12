<?php

namespace App\Domain\Sales\ValueObjects;

/**
 * Calculates restock fees for return/exchange transactions.
 *
 * Rules (from original prompt):
 *   - Configurable restock fee: default 10% for non-defective returns within 30 days
 *
 * Interpretation:
 *   - Defective returns: no restock fee regardless of timing
 *   - Non-defective, within 30 days: default 10% restock fee
 *   - Non-defective, beyond 30 days: return may still be accepted but fee logic
 *     is configurable (default: still 10%, documented in questions.md)
 *
 * Immutable value object — no instances.
 */
final class RestockFeePolicy
{
    private const DEFAULT_RESTOCK_PERCENT_FALLBACK = 10.0;
    private const QUALIFYING_RETURN_DAYS_FALLBACK  = 30;

    private function __construct() {}

    /**
     * Default restock fee percentage for non-defective returns (resolved from config).
     */
    public static function defaultFeePercent(): float
    {
        return (float) config('meridian.sales.restock_fee_default_percent', self::DEFAULT_RESTOCK_PERCENT_FALLBACK);
    }

    /**
     * Qualifying return window in days (resolved from config).
     */
    public static function qualifyingDays(): int
    {
        return (int) config('meridian.sales.restock_fee_qualifying_days', self::QUALIFYING_RETURN_DAYS_FALLBACK);
    }

    /**
     * Calculate the restock fee amount.
     *
     * @param float $returnAmount     The total value of items being returned
     * @param bool  $isDefective      Whether the return reason is defective/damaged
     * @param int   $daysElapsed      Days since the original sale date
     * @param float $feePercent       Override fee percentage (default: DEFAULT_RESTOCK_PERCENT)
     *
     * @return float The restock fee amount (0.0 if no fee applies)
     */
    public static function calculateFee(
        float $returnAmount,
        bool $isDefective,
        int $daysElapsed,
        ?float $feePercent = null
    ): float {
        $feePercent ??= self::defaultFeePercent();
        // Defective returns are always exempt from restock fees
        if ($isDefective) {
            return 0.0;
        }

        // Non-defective returns incur the configured restock fee
        // The fee applies regardless of whether the return is within the qualifying window,
        // as the window determines eligibility, not fee waiver.
        // Returns beyond the qualifying window may be rejected outright (handled at application layer).
        return round($returnAmount * ($feePercent / 100), 2);
    }

    /**
     * Whether a non-defective return is within the qualifying window.
     *
     * Returns outside this window may be refused at the application layer,
     * or accepted with a higher fee — that decision belongs to business rules
     * configured per site (not enforced here).
     */
    public static function isWithinQualifyingWindow(int $daysElapsed): bool
    {
        return $daysElapsed <= self::qualifyingDays();
    }

    /**
     * Calculate the net refund amount after subtracting the restock fee.
     *
     * @param float $returnAmount  Total return value
     * @param float $restockFee    Restock fee amount (from calculateFee)
     */
    public static function calculateRefundAmount(float $returnAmount, float $restockFee): float
    {
        return round(max(0.0, $returnAmount - $restockFee), 2);
    }
}
