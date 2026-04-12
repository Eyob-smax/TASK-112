<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToDoItem extends Model
{
    use HasUuids;

    protected $table = 'to_do_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'workflow_node_id',
        'type',
        'title',
        'body',
        'due_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at'       => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workflowNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowNode::class);
    }
}
