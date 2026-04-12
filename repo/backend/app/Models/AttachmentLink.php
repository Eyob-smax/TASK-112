<?php

namespace App\Models;

use App\Domain\Attachment\Enums\LinkStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttachmentLink extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'attachment_id',
        'token',
        'expires_at',
        'is_single_use',
        'consumed_at',
        'consumed_by',
        'revoked_at',
        'revoked_by',
        'created_by',
        'ip_restriction',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'    => 'datetime',
            'consumed_at'   => 'datetime',
            'revoked_at'    => 'datetime',
            'is_single_use' => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    public function consumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
