<?php

namespace App\Domain\Sales\ValueObjects;

/**
 * Document number format for sales documents.
 *
 * Rules (from original prompt):
 *   - Unique, date-prefixed, and sequential per site
 *
 * From questions.md (ambiguity #6):
 *   - Reset cadence: per site per calendar day
 *   - Format: SITE-YYYYMMDD-000001
 *
 * Immutable value object — no instances.
 */
final class DocumentNumberFormat
{
    /** Pattern for a valid document number: SITE-YYYYMMDD-NNNNNN */
    private const PATTERN = '/^([A-Z0-9]{2,10})-(\d{8})-(\d{6})$/';

    private function __construct() {}

    /**
     * Generate a formatted document number.
     *
     * @param string $siteCode     Site identifier (2–10 uppercase alphanumeric chars)
     * @param \DateTimeImmutable $date   The business date for this document
     * @param int    $sequence     The sequential number (must be > 0, max 999999)
     */
    public static function format(string $siteCode, \DateTimeImmutable $date, int $sequence): string
    {
        return sprintf(
            '%s-%s-%06d',
            strtoupper(trim($siteCode)),
            $date->format('Ymd'),
            $sequence
        );
    }

    /**
     * Whether the given string is a valid document number.
     */
    public static function isValid(string $documentNumber): bool
    {
        return (bool) preg_match(self::PATTERN, $documentNumber);
    }

    /**
     * Parse a document number into its components.
     *
     * @return array{site_code: string, date: string, sequence: int}|null
     */
    public static function parse(string $documentNumber): ?array
    {
        if (!preg_match(self::PATTERN, $documentNumber, $matches)) {
            return null;
        }

        return [
            'site_code' => $matches[1],
            'date'      => $matches[2],
            'sequence'  => (int) $matches[3],
        ];
    }

    /**
     * Extract just the site code from a document number.
     */
    public static function extractSiteCode(string $documentNumber): ?string
    {
        return self::parse($documentNumber)['site_code'] ?? null;
    }

    /**
     * Extract just the date component from a document number.
     */
    public static function extractDate(string $documentNumber): ?string
    {
        return self::parse($documentNumber)['date'] ?? null;
    }
}
