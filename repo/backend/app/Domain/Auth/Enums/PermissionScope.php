<?php

namespace App\Domain\Auth\Enums;

enum PermissionScope: string
{
    case OwnDepartment   = 'own_department';
    case CrossDepartment = 'cross_department';
    case SystemWide      = 'system_wide';

    public function label(): string
    {
        return match ($this) {
            self::OwnDepartment   => 'Own Department Only',
            self::CrossDepartment => 'Cross-Department Access',
            self::SystemWide      => 'System-Wide Access',
        };
    }

    /**
     * Whether this scope allows access to records owned by other departments.
     */
    public function allowsCrossDepartmentAccess(): bool
    {
        return match ($this) {
            self::OwnDepartment => false,
            default             => true,
        };
    }
}
