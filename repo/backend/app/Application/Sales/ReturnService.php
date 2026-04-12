<?php

namespace App\Application\Sales;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Sales\Enums\InventoryMovementType;
use App\Domain\Sales\Enums\ReturnReasonCode;
use App\Domain\Sales\ValueObjects\RestockFeePolicy;
use App\Exceptions\Sales\InvalidSalesTransitionException;
use App\Exceptions\Sales\ReturnWindowExpiredException;
use App\Infrastructure\Persistence\EloquentSalesRepository;
use App\Models\InventoryMovement;
use App\Models\ReturnRecord;
use App\Models\SalesDocument;
use App\Models\User;
use Illuminate\Support\Str;

class ReturnService
{
    public function __construct(
        private readonly EloquentSalesRepository $repo,
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * Create a return record for a completed sales document.
     *
     * Validates:
     * - The sales document is in Completed status (allowsReturn())
     * - For non-defective returns: the qualifying return window has not elapsed
     *
     * Calculates restock fee and refund amount via RestockFeePolicy.
     */
    public function createReturn(
        User $user,
        SalesDocument $salesDoc,
        array $data,
        string $ipAddress,
        string $operationType = 'return'
    ): ReturnRecord
    {
        if (!$salesDoc->status->allowsReturn()) {
            throw new InvalidSalesTransitionException($salesDoc->status->value, 'return');
        }

        if (!in_array($operationType, ['return', 'exchange'], true)) {
            $operationType = 'return';
        }

        // Calculate days elapsed since sale was completed
        $completedAt  = $salesDoc->completed_at ?? now();
        $daysElapsed  = (int) $completedAt->diffInDays(now());

        $reasonCode  = ReturnReasonCode::from($data['reason_code']);
        $isDefective = $reasonCode->isDefective();

        // Non-defective returns outside the qualifying window are rejected
        if (!$isDefective && !RestockFeePolicy::isWithinQualifyingWindow($daysElapsed)) {
            throw new ReturnWindowExpiredException($daysElapsed, RestockFeePolicy::qualifyingDays());
        }

        $returnAmount   = (float) $data['return_amount'];
        $feePercent     = isset($data['restock_fee_percent'])
                            ? (float) $data['restock_fee_percent']
                            : RestockFeePolicy::defaultFeePercent();

        $restockFee   = RestockFeePolicy::calculateFee($returnAmount, $isDefective, $daysElapsed, $feePercent);
        $refundAmount = RestockFeePolicy::calculateRefundAmount($returnAmount, $restockFee);

        $returnDocumentNumber = $this->repo->nextDocumentNumber(
            $salesDoc->site_code . ($operationType === 'exchange' ? 'E' : 'R'),
            new \DateTimeImmutable()
        );

        $return = ReturnRecord::create([
            'sales_document_id'      => $salesDoc->id,
            'return_document_number' => $returnDocumentNumber,
            'reason_code'            => $reasonCode,
            'reason_detail'          => $data['reason_detail'] ?? null,
            'is_defective'           => $isDefective,
            'restock_fee_percent'    => $feePercent,
            'restock_fee_amount'     => $restockFee,
            'return_amount'          => $returnAmount,
            'refund_amount'          => $refundAmount,
            'operation_type'         => $operationType,
            'status'                 => 'pending',
            'created_by'             => $user->id,
        ]);

        $afterHash = hash('sha256', json_encode($return->toArray()));
        $this->recordAudit(AuditAction::ReturnCreate, $user->id, ReturnRecord::class, $return->id, $ipAddress, afterHash: $afterHash);

        return $return->load(['salesDocument']);
    }

    /**
     * Complete a pending return and create compensating stock-in inventory movements.
     */
    public function completeReturn(User $user, ReturnRecord $return, string $ipAddress): ReturnRecord
    {
        if ($return->status !== 'pending') {
            throw new InvalidSalesTransitionException($return->status, 'completed');
        }

        $beforeHash = hash('sha256', json_encode($return->toArray()));
        $return->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'completed_by' => $user->id,
        ]);
        $afterHash = hash('sha256', json_encode($return->fresh()->toArray()));

        // Create compensating stock-in movements for each line item on the original sale
        $salesDoc = $return->salesDocument()->with('lineItems')->first();

        if ($salesDoc) {
            foreach ($salesDoc->lineItems as $item) {
                $movement = InventoryMovement::create([
                    'movement_type'  => InventoryMovementType::Return,
                    'return_id'      => $return->id,
                    'product_code'   => $item->product_code,
                    'quantity_delta' => +abs((float) $item->quantity),
                    'created_by'     => $user->id,
                    'movement_at'    => now(),
                ]);

                // Dedicated audit event for the compensating inventory side-effect write.
                $this->recordAudit(
                    AuditAction::Create,
                    $user->id,
                    InventoryMovement::class,
                    $movement->id,
                    $ipAddress,
                    payload:   ['parent_return_id' => $return->id, 'product_code' => $item->product_code],
                    afterHash: hash('sha256', json_encode($movement->toArray())),
                );
            }
        }

        $this->recordAudit(AuditAction::ReturnComplete, $user->id, ReturnRecord::class, $return->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $return->fresh()->load(['salesDocument']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
