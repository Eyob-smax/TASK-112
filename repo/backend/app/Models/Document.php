<?php

namespace App\Models;

use App\Domain\Auth\Enums\PermissionScope;
use App\Domain\Document\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'title',
        'document_type',
        'department_id',
        'owner_id',
        'status',
        'is_archived',
        'archived_at',
        'archived_by',
        'access_control_scope',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'status'               => DocumentStatus::class,
            'access_control_scope' => PermissionScope::class,
            'is_archived'          => 'boolean',
            'archived_at'          => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }
}
