<?php

namespace App\Models;

use App\Domain\Configuration\Enums\RolloutStatus;
use App\Domain\Configuration\Enums\RolloutTargetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConfigurationVersion extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'configuration_set_id',
        'version_number',
        'payload',
        'status',
        'canary_target_type',
        'canary_target_count',
        'canary_eligible_count',
        'canary_percent',
        'canary_started_at',
        'promoted_at',
        'rolled_back_at',
        'created_by',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'             => 'array',
            'status'              => RolloutStatus::class,
            'canary_target_type'  => RolloutTargetType::class,
            'canary_target_count' => 'integer',
            'canary_eligible_count' => 'integer',
            'canary_percent'      => 'float',
            'version_number'      => 'integer',
            'canary_started_at'   => 'datetime',
            'promoted_at'         => 'datetime',
            'rolled_back_at'      => 'datetime',
            'activated_at'        => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function configurationSet(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSet::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canaryTargets(): HasMany
    {
        return $this->hasMany(CanaryRolloutTarget::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(ConfigurationRule::class);
    }
}
