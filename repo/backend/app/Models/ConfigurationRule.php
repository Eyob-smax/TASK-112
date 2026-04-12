<?php

namespace App\Models;

use App\Domain\Configuration\Enums\PolicyType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigurationRule extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'configuration_version_id',
        'rule_type',
        'rule_key',
        'rule_value',
        'is_active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'rule_type'  => PolicyType::class,
            'rule_value' => 'array',
            'is_active'  => 'boolean',
            'priority'   => 'integer',
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
