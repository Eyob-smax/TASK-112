<?php

namespace App\Models;

use App\Domain\Document\Enums\VersionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentVersion extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'document_id',
        'version_number',
        'status',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size_bytes',
        'sha256_fingerprint',
        'page_count',
        'sheet_count',
        'is_previewable',
        'thumbnail_available',
        'created_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status'             => VersionStatus::class,
            'is_previewable'     => 'boolean',
            'thumbnail_available' => 'boolean',
            'version_number'     => 'integer',
            'file_size_bytes'    => 'integer',
            'published_at'       => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function downloadRecords(): HasMany
    {
        return $this->hasMany(DocumentDownloadRecord::class);
    }
}
