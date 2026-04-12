<?php

namespace App\Models;

use App\Domain\Audit\Enums\AuditAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEvent extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * CRITICAL: audit_events is append-only.
     * Only created_at exists — no updated_at column in the schema.
     */
    const UPDATED_AT = null;

    /**
     * Disable automatic timestamp management entirely; created_at is set manually.
     */
    public $timestamps = false;

    protected $fillable = [
        'id',
        'correlation_id',
        'actor_id',
        'action',
        'auditable_type',
        'auditable_id',
        'before_hash',
        'after_hash',
        'payload',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'action'     => AuditAction::class,
            'payload'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Append-only enforcement
    // -------------------------------------------------------------------------

    /**
     * Prevent any update to an existing audit event.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('AuditEvent records are append-only and cannot be updated.');
        }

        // Set created_at manually on first insert
        if (empty($this->created_at)) {
            $this->created_at = now();
        }

        return parent::save($options);
    }

    /**
     * Prevent soft or hard deletion of audit events.
     */
    public function delete(): ?bool
    {
        throw new \LogicException('AuditEvent records are append-only and cannot be deleted.');
    }

    /**
     * Prevent force-deletion of audit events.
     */
    public function forceDelete(): ?bool
    {
        throw new \LogicException('AuditEvent records are append-only and cannot be deleted.');
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
