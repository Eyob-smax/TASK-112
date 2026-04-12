<?php

namespace App\Models;

use App\Domain\Workflow\Enums\ApprovalAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workflow_node_id',
        'actor_id',
        'action',
        'reason',
        'target_user_id',
        'actioned_at',
    ];

    protected function casts(): array
    {
        return [
            'action'      => ApprovalAction::class,
            'actioned_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function workflowNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
