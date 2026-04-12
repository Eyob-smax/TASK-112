<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StructuredLog extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * No created_at / updated_at — uses recorded_at instead.
     */
    public $timestamps = false;

    protected $fillable = [
        'id',
        'level',
        'message',
        'context',
        'channel',
        'request_id',
        'recorded_at',
        'retained_until',
    ];

    protected function casts(): array
    {
        return [
            'context'       => 'array',
            'recorded_at'   => 'datetime',
            'retained_until' => 'datetime',
        ];
    }
}
