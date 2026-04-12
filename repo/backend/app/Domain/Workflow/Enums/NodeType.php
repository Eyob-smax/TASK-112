<?php

namespace App\Domain\Workflow\Enums;

enum NodeType: string
{
    case Sequential  = 'sequential';
    case Parallel    = 'parallel';
    case Conditional = 'conditional';

    public function label(): string
    {
        return match ($this) {
            self::Sequential  => 'Sequential Approval',
            self::Parallel    => 'Parallel Sign-Off',
            self::Conditional => 'Conditional Branch',
        };
    }

    /**
     * Whether this node type requires all assigned approvers to act before proceeding.
     */
    public function requiresAllApprovers(): bool
    {
        return $this === self::Parallel;
    }
}
