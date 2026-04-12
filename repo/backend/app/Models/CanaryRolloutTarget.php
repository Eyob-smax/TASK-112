<?php

namespace App\Models;

use App\Domain\Configuration\Enums\RolloutTargetType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanaryRolloutTarget extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'configuration_version_id',
        'target_type',
        'target_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => RolloutTargetType::class,
            'assigned_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function configurationVersion(): BelongsTo
    {
        return $this->belongsTo(ConfigurationVersion::class);
    }
}
