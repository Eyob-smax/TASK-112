<?php

namespace App\Models;

use App\Domain\Auth\Enums\RoleType;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    /**
     * Use UUIDs instead of auto-incrementing integers.
     * The custom roles migration provides UUID PKs.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'type' => RoleType::class,
        ]);
    }
}
