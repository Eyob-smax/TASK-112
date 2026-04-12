<?php

namespace App\Models;

use App\Domain\Auth\Enums\PermissionScope;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    /**
     * Use UUIDs instead of auto-incrementing integers.
     * The custom permissions migration provides UUID PKs + scope column.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'scope' => PermissionScope::class,
        ]);
    }
}
