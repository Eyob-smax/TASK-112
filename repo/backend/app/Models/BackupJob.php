<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BackupJob extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'started_at',
        'completed_at',
        'status',
        'manifest',
        'size_bytes',
        'retention_expires_at',
        'error_message',
        'is_manual',
    ];

    protected function casts(): array
    {
        return [
            'manifest'            => 'array',
            'started_at'          => 'datetime',
            'completed_at'        => 'datetime',
            'retention_expires_at' => 'datetime',
            'size_bytes'          => 'integer',
            'is_manual'           => 'boolean',
        ];
    }
}
