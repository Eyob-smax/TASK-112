<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MetricsSnapshot extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * No created_at / updated_at — uses recorded_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'id',
        'metric_type',
        'value',
        'labels',
        'recorded_at',
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'labels'        => 'array',
            'value'         => 'float',
            'recorded_at'   => 'datetime',
            'retained_until' => 'datetime',
        ];
    }
}
