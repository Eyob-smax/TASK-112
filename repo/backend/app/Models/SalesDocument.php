<?php

namespace App\Models;

use App\Domain\Sales\Enums\SalesStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesDocument extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'sales_documents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'document_number',
        'site_code',
        'status',
        'department_id',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'completed_at',
        'voided_at',
        'voided_reason',
        'workflow_instance_id',
        'outbound_linked_at',
        'outbound_linked_by',
        'total_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'             => SalesStatus::class,
            'total_amount'       => 'float',
            'reviewed_at'        => 'datetime',
            'completed_at'       => 'datetime',
            'voided_at'          => 'datetime',
            'outbound_linked_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(SalesLineItem::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ReturnRecord::class, 'sales_document_id');
    }
}
