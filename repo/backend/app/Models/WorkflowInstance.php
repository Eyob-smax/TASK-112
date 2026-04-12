<?php

namespace App\Models;

use App\Domain\Workflow\Enums\WorkflowStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowInstance extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workflow_template_id',
        'record_type',
        'record_id',
        'status',
        'initiated_by',
        'started_at',
        'completed_at',
        'withdrawn_at',
        'withdrawn_by',
        'withdrawal_reason',
    ];

    protected function casts(): array
    {
        return [
            'status'       => WorkflowStatus::class,
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function withdrawnBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowNode::class);
    }
}
