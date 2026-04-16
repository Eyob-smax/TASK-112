<?php

use App\Domain\Attachment\Enums\AllowedMimeType;
use App\Domain\Attachment\Enums\AttachmentStatus;
use App\Domain\Attachment\Enums\LinkStatus;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Auth\Enums\PermissionScope;
use App\Domain\Auth\Enums\RoleType;
use App\Domain\Configuration\Enums\PolicyType;
use App\Domain\Configuration\Enums\RolloutStatus;
use App\Domain\Configuration\Enums\RolloutTargetType;
use App\Domain\Document\Enums\DocumentStatus;
use App\Domain\Document\Enums\VersionStatus;
use App\Domain\Sales\Enums\InventoryMovementType;
use App\Domain\Sales\Enums\ReturnReasonCode;
use App\Domain\Sales\Enums\SalesStatus;
use App\Domain\Workflow\Enums\ApprovalAction;
use App\Domain\Workflow\Enums\NodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;

/*
|--------------------------------------------------------------------------
| Enum Helper Contracts
|--------------------------------------------------------------------------
| Exercises label(), feature-flag predicates, and transition matrices on
| every domain enum so their branches are fully covered at the unit level.
*/

// -------------------------------------------------------------------------
// PolicyType
// -------------------------------------------------------------------------

it('PolicyType labels every case', function () {
    foreach (PolicyType::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

it('PolicyType affectsPurchasing flags Coupon/Promotion/PurchaseLimit/Blacklist/Whitelist as true', function () {
    $affecting = [
        PolicyType::Coupon, PolicyType::Promotion, PolicyType::PurchaseLimit,
        PolicyType::Blacklist, PolicyType::Whitelist,
    ];
    foreach ($affecting as $case) {
        expect($case->affectsPurchasing())->toBeTrue();
    }
    foreach ([PolicyType::Campaign, PolicyType::LandingTopic, PolicyType::AdSlot, PolicyType::HomepageModule] as $case) {
        expect($case->affectsPurchasing())->toBeFalse();
    }
});

// -------------------------------------------------------------------------
// RoleType
// -------------------------------------------------------------------------

it('RoleType labels every case', function () {
    foreach (RoleType::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

it('RoleType hasOversightAccess true only for Admin and Auditor', function () {
    expect(RoleType::Admin->hasOversightAccess())->toBeTrue();
    expect(RoleType::Auditor->hasOversightAccess())->toBeTrue();
    expect(RoleType::Manager->hasOversightAccess())->toBeFalse();
    expect(RoleType::Staff->hasOversightAccess())->toBeFalse();
    expect(RoleType::Viewer->hasOversightAccess())->toBeFalse();
});

// -------------------------------------------------------------------------
// PermissionScope
// -------------------------------------------------------------------------

it('PermissionScope labels every case', function () {
    foreach (PermissionScope::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

it('PermissionScope allowsCrossDepartmentAccess true for CrossDepartment and SystemWide only', function () {
    expect(PermissionScope::CrossDepartment->allowsCrossDepartmentAccess())->toBeTrue();
    expect(PermissionScope::SystemWide->allowsCrossDepartmentAccess())->toBeTrue();
    expect(PermissionScope::OwnDepartment->allowsCrossDepartmentAccess())->toBeFalse();
});

// -------------------------------------------------------------------------
// ApprovalAction
// -------------------------------------------------------------------------

it('ApprovalAction requiresReason true only for Reject and Reassign', function () {
    expect(ApprovalAction::Reject->requiresReason())->toBeTrue();
    expect(ApprovalAction::Reassign->requiresReason())->toBeTrue();
    expect(ApprovalAction::Approve->requiresReason())->toBeFalse();
    expect(ApprovalAction::AddApprover->requiresReason())->toBeFalse();
    expect(ApprovalAction::Withdraw->requiresReason())->toBeFalse();
});

it('ApprovalAction finalizesNode true for Approve/Reject/Withdraw', function () {
    expect(ApprovalAction::Approve->finalizesNode())->toBeTrue();
    expect(ApprovalAction::Reject->finalizesNode())->toBeTrue();
    expect(ApprovalAction::Withdraw->finalizesNode())->toBeTrue();
    expect(ApprovalAction::Reassign->finalizesNode())->toBeFalse();
    expect(ApprovalAction::AddApprover->finalizesNode())->toBeFalse();
});

// -------------------------------------------------------------------------
// NodeType
// -------------------------------------------------------------------------

it('NodeType labels every case', function () {
    foreach (NodeType::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

it('NodeType requiresAllApprovers true only for Parallel', function () {
    expect(NodeType::Parallel->requiresAllApprovers())->toBeTrue();
    expect(NodeType::Sequential->requiresAllApprovers())->toBeFalse();
    expect(NodeType::Conditional->requiresAllApprovers())->toBeFalse();
});

// -------------------------------------------------------------------------
// InventoryMovementType
// -------------------------------------------------------------------------

it('InventoryMovementType decreasesStock true only for Sale', function () {
    expect(InventoryMovementType::Sale->decreasesStock())->toBeTrue();
    foreach ([InventoryMovementType::Return, InventoryMovementType::Restock, InventoryMovementType::Adjustment] as $case) {
        expect($case->decreasesStock())->toBeFalse();
    }
});

it('InventoryMovementType increasesStock true for Return/Restock/Adjustment only', function () {
    expect(InventoryMovementType::Sale->increasesStock())->toBeFalse();
    foreach ([InventoryMovementType::Return, InventoryMovementType::Restock, InventoryMovementType::Adjustment] as $case) {
        expect($case->increasesStock())->toBeTrue();
    }
});

// -------------------------------------------------------------------------
// RolloutTargetType
// -------------------------------------------------------------------------

it('RolloutTargetType labels both cases', function () {
    expect(RolloutTargetType::Store->label())->toBe('Store');
    expect(RolloutTargetType::User->label())->toBe('User');
});

// -------------------------------------------------------------------------
// ReturnReasonCode
// -------------------------------------------------------------------------

it('ReturnReasonCode labels every case', function () {
    foreach (ReturnReasonCode::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

it('ReturnReasonCode only Defective is considered defective and restock-fee exempt', function () {
    expect(ReturnReasonCode::Defective->isDefective())->toBeTrue();
    expect(ReturnReasonCode::Defective->eligibleForRestockFee())->toBeFalse();
    foreach ([ReturnReasonCode::WrongItem, ReturnReasonCode::NotAsDescribed, ReturnReasonCode::ChangedMind, ReturnReasonCode::Other] as $case) {
        expect($case->isDefective())->toBeFalse();
        expect($case->eligibleForRestockFee())->toBeTrue();
    }
});

// -------------------------------------------------------------------------
// DocumentStatus
// -------------------------------------------------------------------------

it('DocumentStatus editability and downloadability rules', function () {
    expect(DocumentStatus::Draft->isEditable())->toBeTrue();
    expect(DocumentStatus::Published->isEditable())->toBeTrue();
    expect(DocumentStatus::Archived->isEditable())->toBeFalse();

    expect(DocumentStatus::Draft->isDownloadable())->toBeFalse();
    expect(DocumentStatus::Published->isDownloadable())->toBeTrue();
    expect(DocumentStatus::Archived->isDownloadable())->toBeTrue();
});

it('DocumentStatus transitions: Draft→Published, Published→Archived, Archived terminal', function () {
    expect(DocumentStatus::Draft->canTransitionTo(DocumentStatus::Published))->toBeTrue();
    expect(DocumentStatus::Draft->canTransitionTo(DocumentStatus::Archived))->toBeFalse();
    expect(DocumentStatus::Published->canTransitionTo(DocumentStatus::Archived))->toBeTrue();
    expect(DocumentStatus::Published->canTransitionTo(DocumentStatus::Draft))->toBeFalse();
    expect(DocumentStatus::Archived->allowedTransitions())->toBe([]);
});

// -------------------------------------------------------------------------
// WorkflowStatus
// -------------------------------------------------------------------------

it('WorkflowStatus terminal and actionable predicates', function () {
    foreach ([WorkflowStatus::Approved, WorkflowStatus::Rejected, WorkflowStatus::Withdrawn, WorkflowStatus::Expired] as $terminal) {
        expect($terminal->isTerminal())->toBeTrue();
        expect($terminal->isActionable())->toBeFalse();
        expect($terminal->allowsWithdrawal())->toBeFalse();
        expect($terminal->allowedTransitions())->toBe([]);
    }
    foreach ([WorkflowStatus::Pending, WorkflowStatus::InProgress] as $actionable) {
        expect($actionable->isTerminal())->toBeFalse();
        expect($actionable->isActionable())->toBeTrue();
        expect($actionable->allowsWithdrawal())->toBeTrue();
    }
});

it('WorkflowStatus.allowedTransitions for Pending and InProgress', function () {
    expect(WorkflowStatus::Pending->allowedTransitions())
        ->toBe([WorkflowStatus::InProgress, WorkflowStatus::Withdrawn]);
    expect(WorkflowStatus::InProgress->allowedTransitions())
        ->toBe([WorkflowStatus::Approved, WorkflowStatus::Rejected, WorkflowStatus::Withdrawn, WorkflowStatus::Expired]);
});

// -------------------------------------------------------------------------
// RolloutStatus
// -------------------------------------------------------------------------

it('RolloutStatus transitions and predicates', function () {
    expect(RolloutStatus::Draft->canTransitionTo(RolloutStatus::Canary))->toBeTrue();
    expect(RolloutStatus::Draft->canTransitionTo(RolloutStatus::Promoted))->toBeFalse();
    expect(RolloutStatus::Canary->canTransitionTo(RolloutStatus::Promoted))->toBeTrue();
    expect(RolloutStatus::Canary->canTransitionTo(RolloutStatus::RolledBack))->toBeTrue();
    expect(RolloutStatus::Promoted->canTransitionTo(RolloutStatus::RolledBack))->toBeTrue();
    expect(RolloutStatus::RolledBack->allowedTransitions())->toBe([]);

    expect(RolloutStatus::Canary->isActive())->toBeTrue();
    expect(RolloutStatus::Promoted->isActive())->toBeTrue();
    expect(RolloutStatus::Draft->isActive())->toBeFalse();
    expect(RolloutStatus::RolledBack->isActive())->toBeFalse();

    expect(RolloutStatus::Canary->canPromote())->toBeTrue();
    expect(RolloutStatus::Draft->canPromote())->toBeFalse();
    expect(RolloutStatus::Promoted->canPromote())->toBeFalse();
});

// -------------------------------------------------------------------------
// AllowedMimeType
// -------------------------------------------------------------------------

it('AllowedMimeType extension() mapping and supportsWatermark()', function () {
    expect(AllowedMimeType::Pdf->extension())->toBe('pdf');
    expect(AllowedMimeType::Docx->extension())->toBe('docx');
    expect(AllowedMimeType::Xlsx->extension())->toBe('xlsx');
    expect(AllowedMimeType::Png->extension())->toBe('png');
    expect(AllowedMimeType::Jpeg->extension())->toBe('jpg');

    expect(AllowedMimeType::Pdf->supportsWatermark())->toBeTrue();
    expect(AllowedMimeType::Docx->supportsWatermark())->toBeFalse();
});

it('AllowedMimeType values() and extensions() return full lists', function () {
    expect(AllowedMimeType::values())->toContain('application/pdf', 'image/png', 'image/jpeg');
    expect(AllowedMimeType::extensions())->toContain('pdf', 'png', 'jpg');
});

it('AllowedMimeType tryFromMime normalizes case and whitespace', function () {
    expect(AllowedMimeType::tryFromMime('  APPLICATION/PDF  '))->toBe(AllowedMimeType::Pdf);
    expect(AllowedMimeType::tryFromMime('image/png'))->toBe(AllowedMimeType::Png);
    expect(AllowedMimeType::tryFromMime('application/unknown'))->toBeNull();
});

// -------------------------------------------------------------------------
// AttachmentStatus & LinkStatus (simple string backed enums — call cases())
// -------------------------------------------------------------------------

it('AttachmentStatus isAccessible only Active', function () {
    expect(AttachmentStatus::Active->isAccessible())->toBeTrue();
    expect(AttachmentStatus::Expired->isAccessible())->toBeFalse();
    expect(AttachmentStatus::Revoked->isAccessible())->toBeFalse();
});

it('LinkStatus usability and terminal classification', function () {
    expect(LinkStatus::Active->isUsable())->toBeTrue();
    expect(LinkStatus::Active->isTerminal())->toBeFalse();
    foreach ([LinkStatus::Consumed, LinkStatus::Expired, LinkStatus::Revoked] as $terminal) {
        expect($terminal->isUsable())->toBeFalse();
        expect($terminal->isTerminal())->toBeTrue();
    }
});

// -------------------------------------------------------------------------
// VersionStatus & SalesStatus predicates (if any)
// -------------------------------------------------------------------------

it('VersionStatus isCurrent and isDownloadable matrix', function () {
    expect(VersionStatus::Current->isCurrent())->toBeTrue();
    expect(VersionStatus::Superseded->isCurrent())->toBeFalse();
    expect(VersionStatus::Archived->isCurrent())->toBeFalse();

    expect(VersionStatus::Current->isDownloadable())->toBeTrue();
    expect(VersionStatus::Superseded->isDownloadable())->toBeTrue();
    expect(VersionStatus::Archived->isDownloadable())->toBeFalse();
});

it('SalesStatus predicates for edit/outbound/return/void', function () {
    expect(SalesStatus::Draft->isEditable())->toBeTrue();
    expect(SalesStatus::Reviewed->isEditable())->toBeFalse();

    expect(SalesStatus::Completed->allowsOutboundLinkage())->toBeTrue();
    expect(SalesStatus::Draft->allowsOutboundLinkage())->toBeFalse();

    expect(SalesStatus::Completed->allowsReturn())->toBeTrue();
    expect(SalesStatus::Draft->allowsReturn())->toBeFalse();

    expect(SalesStatus::Draft->canBeVoided())->toBeTrue();
    expect(SalesStatus::Reviewed->canBeVoided())->toBeTrue();
    expect(SalesStatus::Completed->canBeVoided())->toBeFalse();
    expect(SalesStatus::Voided->canBeVoided())->toBeFalse();
});

it('SalesStatus transitions: Draft→Reviewed/Voided, Reviewed→Completed/Voided, terminals empty', function () {
    expect(SalesStatus::Draft->canTransitionTo(SalesStatus::Reviewed))->toBeTrue();
    expect(SalesStatus::Draft->canTransitionTo(SalesStatus::Voided))->toBeTrue();
    expect(SalesStatus::Draft->canTransitionTo(SalesStatus::Completed))->toBeFalse();
    expect(SalesStatus::Reviewed->canTransitionTo(SalesStatus::Completed))->toBeTrue();
    expect(SalesStatus::Reviewed->canTransitionTo(SalesStatus::Voided))->toBeTrue();
    expect(SalesStatus::Reviewed->canTransitionTo(SalesStatus::Draft))->toBeFalse();
    expect(SalesStatus::Completed->allowedTransitions())->toBe([]);
    expect(SalesStatus::Voided->allowedTransitions())->toBe([]);
});

// -------------------------------------------------------------------------
// AuditAction predicates
// -------------------------------------------------------------------------

it('AuditAction isSecurityEvent flags authentication actions', function () {
    foreach ([AuditAction::Login, AuditAction::Logout, AuditAction::LoginFailed, AuditAction::Lockout, AuditAction::PasswordChange] as $action) {
        expect($action->isSecurityEvent())->toBeTrue();
    }
    foreach ([AuditAction::Create, AuditAction::Update, AuditAction::Approve, AuditAction::BackupRun] as $action) {
        expect($action->isSecurityEvent())->toBeFalse();
    }
});

it('AuditAction isModification flags state-changing actions', function () {
    foreach ([AuditAction::Create, AuditAction::Update, AuditAction::Delete, AuditAction::Archive, AuditAction::Approve, AuditAction::Reject, AuditAction::Reassign, AuditAction::Withdraw, AuditAction::AddApprover, AuditAction::RolloutStart, AuditAction::RolloutPromote, AuditAction::RolloutBack, AuditAction::SalesComplete, AuditAction::SalesVoid, AuditAction::ReturnComplete, AuditAction::PasswordChange] as $action) {
        expect($action->isModification())->toBeTrue();
    }
    foreach ([AuditAction::Download, AuditAction::LinkCreate, AuditAction::LinkConsume, AuditAction::LinkRevoke, AuditAction::Login, AuditAction::Logout, AuditAction::LoginFailed, AuditAction::Lockout, AuditAction::BackupRun, AuditAction::SalesSubmit, AuditAction::ReturnCreate] as $action) {
        expect($action->isModification())->toBeFalse();
    }
});
