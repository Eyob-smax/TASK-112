<?php

namespace App\Domain\Attachment\ValueObjects;

use App\Domain\Attachment\Enums\AllowedMimeType;

/**
 * Defines the file upload constraints for attachments.
 *
 * Rules (from original prompt):
 *   - Accepted types: PDF, DOCX, XLSX, PNG, JPG only
 *   - Maximum file size: 25 MB per file
 *   - Maximum files per record: 20
 *
 * Immutable value object — no instances.
 */
final class FileConstraints
{
    /** Maximum file size in bytes: 25 MB */
    public const MAX_SIZE_BYTES       = 26_214_400; // 25 * 1024 * 1024

    /** Maximum number of attachments per business record */
    public const MAX_FILES_PER_RECORD = 20;

    private function __construct() {}

    /**
     * Whether the given file size is within the allowed limit.
     */
    public static function isSizeAllowed(int $sizeInBytes): bool
    {
        return $sizeInBytes > 0 && $sizeInBytes <= self::MAX_SIZE_BYTES;
    }

    /**
     * Whether the given MIME type is in the allowed list.
     */
    public static function isMimeAllowed(string $mime): bool
    {
        return AllowedMimeType::tryFromMime($mime) !== null;
    }

    /**
     * Whether adding a new file would exceed the per-record file count limit.
     *
     * @param int $currentCount Current number of active attachments on the record
     * @param int $newFileCount Number of files being added
     */
    public static function wouldExceedFileCount(int $currentCount, int $newFileCount = 1): bool
    {
        return ($currentCount + $newFileCount) > self::MAX_FILES_PER_RECORD;
    }

    /**
     * Allowed MIME types as plain strings.
     *
     * @return list<string>
     */
    public static function allowedMimeTypes(): array
    {
        return AllowedMimeType::values();
    }

    /**
     * Allowed file extensions.
     *
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        return AllowedMimeType::extensions();
    }
}
