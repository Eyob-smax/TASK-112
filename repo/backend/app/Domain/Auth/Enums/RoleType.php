<?php

namespace App\Domain\Auth\Enums;

enum RoleType: string
{
    case Admin    = 'admin';
    case Manager  = 'manager';
    case Staff    = 'staff';
    case Auditor  = 'auditor';
    case Viewer   = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin   => 'Administrator',
            self::Manager => 'Manager',
            self::Staff   => 'Staff',
            self::Auditor => 'Auditor',
            self::Viewer  => 'Viewer',
        };
    }

    /**
     * Whether this role type has system-wide oversight capabilities.
     */
    public function hasOversightAccess(): bool
    {
        return match ($this) {
            self::Admin, self::Auditor => true,
            default                   => false,
        };
    }
}
