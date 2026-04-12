<?php

namespace App\Domain\Attachment\Enums;

/**
 * Permitted MIME types for attachment uploads.
 *
 * Validation must check BOTH the declared Content-Type and the actual file
 * magic bytes to prevent MIME spoofing. Extensions are a secondary check only.
 */
enum AllowedMimeType: string
{
    case Pdf  = 'application/pdf';
    case Docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    case Xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case Png  = 'image/png';
    case Jpeg = 'image/jpeg';

    /**
     * The canonical file extension for this MIME type.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Pdf  => 'pdf',
            self::Docx => 'docx',
            self::Xlsx => 'xlsx',
            self::Png  => 'png',
            self::Jpeg => 'jpg',
        };
    }

    /**
     * Whether this MIME type supports PDF watermarking at download time.
     */
    public function supportsWatermark(): bool
    {
        return $this === self::Pdf;
    }

    /**
     * All allowed MIME type strings as a plain array.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * All allowed file extensions.
     *
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_map(fn (self $type) => $type->extension(), self::cases());
    }

    /**
     * Resolve from a MIME string, returning null if not allowed.
     */
    public static function tryFromMime(string $mime): ?self
    {
        return self::tryFrom(strtolower(trim($mime)));
    }
}
