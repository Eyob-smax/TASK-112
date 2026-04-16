<?php

namespace App\Application\Sales;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Sales\Enums\InventoryMovementType;
use App\Domain\Sales\Enums\SalesStatus;
use App\Exceptions\Sales\InvalidSalesTransitionException;
use App\Exceptions\Sales\OutboundLinkageNotAllowedException;
use App\Infrastructure\Persistence\EloquentSalesRepository;
use App\Models\InventoryMovement;
use App\Models\SalesDocument;
use App\Models\SalesLineItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class SalesDocumentService
{
    public function __construct(
        private readonly EloquentSalesRepository $repo,
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * Create a new sales document in Draft status, atomically assigning a document number.
     */
    public function create(User $user, array $data, string $ipAddress): SalesDocument
    {
        $this->assertCreateDepartmentAccess($user, $data['department_id']);

        $documentNumber = $this->repo->nextDocumentNumber(
            $data['site_code'],
            new \DateTimeImmutable()
        );

        $doc = SalesDocument::create([
            'document_number' => $documentNumber,
            'site_code'       => $data['site_code'],
            'status'          => SalesStatus::Draft->value,
            'department_id'   => $data['department_id'],
            'created_by'      => $user->id,
            'notes'           => $data['notes'] ?? null,
            'total_amount'    => 0.0,
        ]);

        if (!empty($data['line_items'])) {
            $total = 0.0;
            foreach ($data['line_items'] as $index => $item) {
                SalesLineItem::create([
                    'sales_document_id' => $doc->id,
                    'line_number'       => $index + 1,
                    'product_code'      => $item['product_code'],
                    'description'       => $item['description'] ?? null,
                    'quantity'          => (float) $item['quantity'],
                    'unit_price'        => (float) $item['unit_price'],
                    'line_total'        => round((float) $item['quantity'] * (float) $item['unit_price'], 2),
                ]);
                $total += round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            }
            $doc->update(['total_amount' => round($total, 2)]);
        }

        $afterHash = hash('sha256', json_encode($doc->fresh()->toArray()));
        $this->recordAudit(AuditAction::Create, $user->id, SalesDocument::class, $doc->id, $ipAddress, afterHash: $afterHash);

        return $doc->load(['department', 'createdBy', 'lineItems']);
    }

    /**
     * Update notes and/or line items on a Draft document.
     */
    public function update(User $user, SalesDocument $doc, array $data, string $ipAddress): SalesDocument
    {
        $status = $this->resolveStatus($doc, 'edited');
        $beforeHash = hash('sha256', json_encode($doc->toArray()));

        if (!$status->isEditable()) {
            throw new InvalidSalesTransitionException($status->value, 'edited');
        }

        if (array_key_exists('notes', $data)) {
            $doc->update(['notes' => $data['notes']]);
        }

        if (!empty($data['line_items'])) {
            $doc->lineItems()->delete();

            $total = 0.0;
            foreach ($data['line_items'] as $index => $item) {
                SalesLineItem::create([
                    'sales_document_id' => $doc->id,
                    'line_number'       => $index + 1,
                    'product_code'      => $item['product_code'],
                    'description'       => $item['description'] ?? null,
                    'quantity'          => (float) $item['quantity'],
                    'unit_price'        => (float) $item['unit_price'],
                    'line_total'        => round((float) $item['quantity'] * (float) $item['unit_price'], 2),
                ]);
                $total += round((float) $item['quantity'] * (float) $item['unit_price'], 2);
            }
            $doc->update(['total_amount' => round($total, 2)]);
        }

        $afterHash = hash('sha256', json_encode($doc->fresh()->toArray()));
        $this->recordAudit(
            AuditAction::Update,
            $user->id,
            SalesDocument::class,
            $doc->id,
            $ipAddress,
            beforeHash: $beforeHash,
            afterHash: $afterHash
        );

        return $doc->fresh()->load(['department', 'createdBy', 'lineItems']);
    }

    /**
     * Transition draft → reviewed.
     */
    public function submit(User $user, SalesDocument $doc, string $ipAddress): SalesDocument
    {
        $status = $this->resolveStatus($doc, SalesStatus::Reviewed->value);

        if (!$status->canTransitionTo(SalesStatus::Reviewed)) {
            throw new InvalidSalesTransitionException($status->value, SalesStatus::Reviewed->value);
        }

        $beforeHash = hash('sha256', json_encode($doc->toArray()));
        $doc->update([
            'status'      => SalesStatus::Reviewed->value,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
        $afterHash = hash('sha256', json_encode($doc->fresh()->toArray()));

        $this->recordAudit(AuditAction::SalesSubmit, $user->id, SalesDocument::class, $doc->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $doc->fresh()->load(['department', 'createdBy', 'lineItems']);
    }

    /**
     * Transition reviewed → completed and create stock-out inventory movements.
     */
    public function complete(User $user, SalesDocument $doc, string $ipAddress): SalesDocument
    {
        $status = $this->resolveStatus($doc, SalesStatus::Completed->value);

        if (!$status->canTransitionTo(SalesStatus::Completed)) {
            throw new InvalidSalesTransitionException($status->value, SalesStatus::Completed->value);
        }

        $beforeHash = hash('sha256', json_encode($doc->toArray()));
        $doc->update([
            'status'       => SalesStatus::Completed->value,
            'completed_at' => now(),
        ]);

        // Create stock-out movements for each line item
        foreach ($doc->lineItems as $item) {
            $movement = InventoryMovement::create([
                'movement_type'      => InventoryMovementType::Sale,
                'sales_document_id'  => $doc->id,
                'product_code'       => $item->product_code,
                'quantity_delta'     => -abs((float) $item->quantity),
                'created_by'         => $user->id,
                'movement_at'        => now(),
            ]);

            // Dedicated audit event for the inventory side-effect write.
            $this->recordAudit(
                AuditAction::Create,
                $user->id,
                InventoryMovement::class,
                $movement->id,
                $ipAddress,
                payload:   ['parent_document_id' => $doc->id, 'product_code' => $item->product_code],
                afterHash: hash('sha256', json_encode($movement->toArray())),
            );
        }

        $afterHash = hash('sha256', json_encode($doc->fresh()->toArray()));
        $this->recordAudit(AuditAction::SalesComplete, $user->id, SalesDocument::class, $doc->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $doc->fresh()->load(['department', 'createdBy', 'lineItems']);
    }

    /**
     * Void a document (draft or reviewed only).
     */
    public function void(User $user, SalesDocument $doc, string $reason, string $ipAddress): SalesDocument
    {
        $status = $this->resolveStatus($doc, SalesStatus::Voided->value);

        if (!$status->canBeVoided()) {
            throw new InvalidSalesTransitionException($status->value, SalesStatus::Voided->value);
        }

        $beforeHash = hash('sha256', json_encode($doc->toArray()));
        $doc->update([
            'status'       => SalesStatus::Voided->value,
            'voided_at'    => now(),
            'voided_reason' => $reason,
        ]);
        $afterHash = hash('sha256', json_encode($doc->fresh()->toArray()));

        $this->recordAudit(AuditAction::SalesVoid, $user->id, SalesDocument::class, $doc->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $doc->fresh()->load(['department', 'createdBy', 'lineItems']);
    }

    /**
     * Record outbound linkage on a completed document.
     *
     * Requires: document must be in Completed status AND a workflow instance must be
     * linked to the document in the terminal Approved state.
     */
    public function linkOutbound(User $user, SalesDocument $doc, string $ipAddress): SalesDocument
    {
        $status = $this->resolveStatus($doc, 'link_outbound');

        if (!$status->allowsOutboundLinkage()) {
            throw new OutboundLinkageNotAllowedException();
        }

        // A linked workflow instance in terminal Approved state is always required.
        $doc->loadMissing('workflowInstance');
        if ($doc->workflow_instance_id === null
            || $doc->workflowInstance === null
            || $doc->workflowInstance->status !== \App\Domain\Workflow\Enums\WorkflowStatus::Approved) {
            throw new OutboundLinkageNotAllowedException(
                'A workflow instance in Approved state must be linked before outbound linkage.'
            );
        }

        $beforeHash = hash('sha256', json_encode($doc->toArray()));
        $doc->update([
            'outbound_linked_at' => now(),
            'outbound_linked_by' => $user->id,
        ]);
        $afterHash = hash('sha256', json_encode($doc->fresh()->toArray()));

        $this->recordAudit(AuditAction::Update, $user->id, SalesDocument::class, $doc->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $doc->fresh()->load(['department', 'createdBy', 'lineItems']);
    }

    /**
     * Soft-delete a sales document.
     *
     * Records a Delete audit event with before/after hashes for compliance.
     */
    public function delete(User $user, SalesDocument $doc, string $ipAddress): void
    {
        $beforeHash = hash('sha256', json_encode($doc->toArray()));
        $doc->delete();
        $afterHash = hash('sha256', json_encode($doc->toArray()));

        $this->recordAudit(AuditAction::Delete, $user->id, SalesDocument::class, $doc->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertCreateDepartmentAccess(User $user, string $departmentId): void
    {
        if ($user->hasRole(['admin', 'manager', 'auditor'])) {
            return;
        }

        if ($user->department_id !== null && $user->department_id === $departmentId) {
            return;
        }

        throw new AuthorizationException('You are not authorized to create sales documents for this department.');
    }

    private function resolveStatus(SalesDocument $doc, string $target): SalesStatus
    {
        $status = $doc->status;

        if ($status instanceof SalesStatus) {
            return $status;
        }

        $from = is_string($status) && $status !== '' ? $status : 'unknown';
        throw new InvalidSalesTransitionException($from, $target);
    }

    private function recordAudit(
        AuditAction $action,
        ?string $actorId,
        string $auditableType,
        string $auditableId,
        string $ipAddress,
        array $payload = [],
        ?string $beforeHash = null,
        ?string $afterHash = null,
    ): void {
        $idempotencyKey = request()->header('X-Idempotency-Key');
        $correlationId  = $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey . ':' . $auditableId . ':' . $action->value)
            : (string) Str::uuid();

        $this->audit->record(
            correlationId: $correlationId,
            action:        $action,
            actorId:       $actorId,
            auditableType: $auditableType,
            auditableId:   $auditableId,
            beforeHash:    $beforeHash,
            afterHash:     $afterHash,
            payload:       $payload,
            ipAddress:     $ipAddress,
        );
    }
}
