<?php

namespace App\Models;

use App\Domain\Workflow\Enums\NodeType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTemplateNode extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'workflow_template_id',
        'node_order',
        'node_type',
        'role_required',
        'user_required',
        'sla_business_days',
        'is_parallel',
        'condition_field',
        'condition_operator',
        'condition_value',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'node_type'         => NodeType::class,
            'node_order'        => 'integer',
            'sla_business_days' => 'integer',
            'is_parallel'       => 'boolean',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    public function roleRequired(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_required');
    }

    public function userRequired(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_required');
    }
}
