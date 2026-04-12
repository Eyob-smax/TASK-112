<?php

namespace App\Models;

use App\Domain\Auth\Enums\RoleType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasRoles, HasUuids, SoftDeletes;

    /**
     * The guard name used by Spatie and Sanctum.
     */
    protected string $guard_name = 'sanctum';

    /**
     * Disable auto-incrementing integer PK — we use UUIDs.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'username',
        'email',
        'password_hash',
        'display_name',
        'department_id',
        'is_active',
    ];

    protected $hidden = [
        'password_hash',
    ];

    protected function casts(): array
    {
        return [
            'is_active'          => 'boolean',
            'locked_until'       => 'datetime',
            'last_failed_at'     => 'datetime',
            'failed_attempt_count' => 'integer',
            'deleted_at'         => 'datetime',
        ];
    }

    /**
     * Laravel's auth system uses getAuthPassword() to retrieve the stored hash.
     * Our column is named password_hash rather than the default password.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
