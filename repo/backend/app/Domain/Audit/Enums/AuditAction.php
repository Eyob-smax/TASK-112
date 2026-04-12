<?php

namespace App\Domain\Audit\Enums;

enum AuditAction: string
{
    // General CRUD
    case Create     = 'create';
    case Update     = 'update';
    case Delete     = 'delete';

    // Document operations
    case Archive    = 'archive';
    case Download   = 'download';

    // Attachment operations
    case LinkCreate  = 'link_create';
    case LinkConsume = 'link_consume';
    case LinkRevoke  = 'link_revoke';

    // Workflow operations
    case Approve    = 'approve';
    case Reject     = 'reject';
    case Reassign   = 'reassign';
    case AddApprover = 'add_approver';
    case Withdraw   = 'withdraw';

    // Configuration operations
    case RolloutStart   = 'rollout_start';
    case RolloutPromote = 'rollout_promote';
    case RolloutBack    = 'rollout_back';

    // Sales operations
    case SalesSubmit   = 'sales_submit';
    case SalesComplete = 'sales_complete';
    case SalesVoid     = 'sales_void';
    case ReturnCreate  = 'return_create';
    case ReturnComplete = 'return_complete';

    // Authentication operations
    case Login       = 'login';
    case Logout      = 'logout';
    case LoginFailed = 'login_failed';
    case Lockout     = 'lockout';
    case PasswordChange = 'password_change';

    // System operations
    case BackupRun   = 'backup_run';

    /**
     * Whether this action involves a security-sensitive event.
     */
    public function isSecurityEvent(): bool
    {
        return match ($this) {
            self::Login, self::Logout, self::LoginFailed,
            self::Lockout, self::PasswordChange          => true,
            default                                      => false,
        };
    }

    /**
     * Whether this action modifies a record (has a before/after hash).
     *
     * All actions listed here are subject to the schema-level CHECK constraints
     * (chk_modification_requires_after_hash, chk_transition_requires_before_hash)
     * and the application-layer guard in EloquentAuditEventRepository::record().
     */
    public function isModification(): bool
    {
        return match ($this) {
            // General CRUD
            self::Create, self::Update, self::Delete, self::Archive,
            // Workflow state changes (approval decisions)
            self::Approve, self::Reject,
            // Workflow mutations (assignment/withdrawal — before+after required)
            self::Reassign, self::Withdraw,
            // Workflow additions (create-like — afterHash required)
            self::AddApprover,
            // Configuration rollout lifecycle (all carry before+after hashes)
            self::RolloutStart, self::RolloutPromote, self::RolloutBack,
            // Sales and returns lifecycle
            self::SalesComplete, self::SalesVoid, self::ReturnComplete,
            // Security-sensitive record mutations
            self::PasswordChange                             => true,
            default                                          => false,
        };
    }
}
