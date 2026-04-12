<?php

namespace App\Models;

use App\Domain\Workflow\Enums\NodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowNode extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workflow_instance_id',
        'template_node_id',
        'node_order',
        'node_type',
        'assigned_to',
        'status',
        'sla_due_at',
        'reminded_at',
        'completed_at',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'status'       => WorkflowStatus::class,
            'node_type'    => NodeType::class,
            'node_order'   => 'integer',
            'sla_due_at'   => 'datetime',
            'reminded_at'  => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function templateNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplateNode::class, 'template_node_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }
}
