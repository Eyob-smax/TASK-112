<?php

namespace App\Domain\Workflow\Enums;

enum ApprovalAction: string
{
    case Approve    = 'approve';
    case Reject     = 'reject';
    case Reassign   = 'reassign';
    case AddApprover = 'add_approver';
    case Withdraw   = 'withdraw';

    /**
     * Whether this action requires a mandatory reason to be provided.
     */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::Reject, self::Reassign => true,
            default                      => false,
        };
    }

    /**
     * Whether this action finalizes the node (moves it to a terminal state).
     */
    public function finalizesNode(): bool
    {
        return match ($this) {
            self::Approve, self::Reject, self::Withdraw => true,
            default                                     => false,
        };
    }
}
