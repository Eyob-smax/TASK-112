<?php

namespace App\Domain\Sales\Enums;

enum InventoryMovementType: string
{
    case Sale       = 'sale';
    case Return     = 'return';
    case Restock    = 'restock';
    case Adjustment = 'adjustment';

    /**
     * Whether this movement type decreases available stock.
     */
    public function decreasesStock(): bool
    {
        return $this === self::Sale;
    }

    /**
     * Whether this movement type increases available stock.
     */
    public function increasesStock(): bool
    {
        return match ($this) {
            self::Return, self::Restock, self::Adjustment => true,
            default                                        => false,
        };
    }
}
