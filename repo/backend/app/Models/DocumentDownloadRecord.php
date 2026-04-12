<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentDownloadRecord extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'document_version_id',
        'downloaded_by',
        'downloaded_at',
        'watermark_text',
        'watermark_applied',
        'is_pdf',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'downloaded_at'    => 'datetime',
            'watermark_applied' => 'boolean',
            'is_pdf'           => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    public function downloadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'downloaded_by');
    }
}
