<?php

namespace App\Domain\Sales\Enums;

enum ReturnReasonCode: string
{
    case Defective      = 'defective';
    case WrongItem      = 'wrong_item';
    case NotAsDescribed = 'not_as_described';
    case ChangedMind    = 'changed_mind';
    case Other          = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Defective      => 'Defective / Damaged',
            self::WrongItem      => 'Wrong Item Received',
            self::NotAsDescribed => 'Not as Described',
            self::ChangedMind    => 'Changed Mind',
            self::Other          => 'Other',
        };
    }

    /**
     * Whether this reason code classifies the return as a defective item.
     * Defective returns are exempt from restock fees.
     */
    public function isDefective(): bool
    {
        return $this === self::Defective;
    }

    /**
     * Whether restock fees apply for this reason code.
     * Non-defective returns within the qualifying window incur the configured restock fee.
     */
    public function eligibleForRestockFee(): bool
    {
        return !$this->isDefective();
    }
}
