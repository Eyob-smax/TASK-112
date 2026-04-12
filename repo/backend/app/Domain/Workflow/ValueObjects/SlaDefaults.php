<?php

namespace App\Domain\Workflow\ValueObjects;

/**
 * Workflow SLA calculation service.
 *
 * Rules (from original prompt):
 *   - Default SLA: 2 business days per approval node
 *
 * From questions.md (ambiguity #4):
 *   - Business days = Monday through Friday in system-local timezone
 *   - No custom holiday calendar in initial delivery
 *   - SLA deadline is at the same local clock time on the calculated business day
 *
 * Immutable value object — no instances.
 */
final class SlaDefaults
{
    public const DEFAULT_SLA_BUSINESS_DAYS = 2;

    private function __construct() {}

    /**
     * Calculate the SLA due date by adding business days to a start timestamp.
     *
     * Weekends (Saturday, Sunday) are skipped.
     * The result preserves the time-of-day component from $startAt.
     *
     * @param \DateTimeImmutable $startAt          The start timestamp (typically node assignment time)
     * @param int                $businessDays     Number of business days to add (default: 2)
     * @param \DateTimeZone|null $timezone         Timezone for weekend calculation (default: system timezone)
     */
    public static function calculateDueAt(
        \DateTimeImmutable $startAt,
        int $businessDays = self::DEFAULT_SLA_BUSINESS_DAYS,
        ?\DateTimeZone $timezone = null
    ): \DateTimeImmutable {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());

        // Convert to the target timezone for day-of-week calculations
        $current = $startAt->setTimezone($timezone);
        $remaining = $businessDays;

        while ($remaining > 0) {
            $current = $current->modify('+1 day');
            $dayOfWeek = (int) $current->format('N'); // 1=Mon, 7=Sun
            if ($dayOfWeek <= 5) { // Monday through Friday
                $remaining--;
            }
        }

        return $current;
    }

    /**
     * Whether a given timestamp is a business day (Mon–Fri).
     */
    public static function isBusinessDay(\DateTimeImmutable $date, ?\DateTimeZone $timezone = null): bool
    {
        $timezone ??= new \DateTimeZone(date_default_timezone_get());
        $dayOfWeek = (int) $date->setTimezone($timezone)->format('N');
        return $dayOfWeek <= 5;
    }

    /**
     * Whether the SLA for a node has been breached (due date has passed).
     */
    public static function isSlaBreached(\DateTimeImmutable $dueAt, \DateTimeImmutable $now): bool
    {
        return $now > $dueAt;
    }
}
