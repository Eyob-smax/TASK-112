<?php

namespace App\Models;

use App\Domain\Attachment\Enums\AttachmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attachment extends Model
{
    use HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'record_type',
        'record_id',
        'original_filename',
        'mime_type',
        'file_size_bytes',
        'sha256_fingerprint',
        'encrypted_path',
        'encryption_key_id',
        'status',
        'validity_days',
        'expires_at',
        'uploaded_by',
        'department_id',
    ];

    protected function casts(): array
    {
        return [
            'status'          => AttachmentStatus::class,
            'expires_at'      => 'datetime',
            'file_size_bytes' => 'integer',
            'validity_days'   => 'integer',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function record(): MorphTo
    {
        return $this->morphTo('record');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(AttachmentLink::class);
    }
}
