<?php

namespace App\Domain\Configuration\Enums;

enum RolloutTargetType: string
{
    case Store = 'store';
    case User  = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Store => 'Store',
            self::User  => 'User',
        };
    }
}
