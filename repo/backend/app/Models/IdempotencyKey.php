<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Only created_at exists — no updated_at column in the schema.
     */
    const UPDATED_AT = null;

    public $timestamps = false;

    protected $fillable = [
        'id',
        'key_hash',
        'actor_scope_hash',
        'http_method',
        'request_path',
        'request_hash',
        'response_status',
        'response_body',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body'   => 'array',
            'response_status' => 'integer',
            'expires_at'      => 'datetime',
            'created_at'      => 'datetime',
        ];
    }
}
