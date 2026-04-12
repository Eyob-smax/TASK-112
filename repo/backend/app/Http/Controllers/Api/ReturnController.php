<?php

namespace App\Http\Controllers\Api;

use App\Application\Sales\ReturnService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CompleteReturnRequest;
use App\Http\Requests\Sales\StoreReturnRequest;
use App\Models\ReturnRecord;
use App\Models\SalesDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnService $service,
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * GET /api/v1/sales/{document}/returns
     */
    public function index(Request $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $returns = $document->returns()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $returns->map(fn(ReturnRecord $r) => $this->returnShape($r))->values(),
        ]);
    }

    /**
     * GET /api/v1/sales/{document}/exchanges
     */
    public function indexExchanges(Request $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $exchanges = $document->returns()
            ->where('operation_type', 'exchange')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $exchanges->map(fn(ReturnRecord $r) => $this->returnShape($r))->values(),
        ]);
    }

    /**
     * POST /api/v1/sales/{document}/returns
     */
    public function store(StoreReturnRequest $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('createReturn', $document);

        $return = $this->service->createReturn(
            $request->user(),
            $document,
            $request->validated(),
            $request->ip(),
            'return'
        );

        return response()->json(['data' => $this->returnShape($return)], 201);
    }

    /**
     * POST /api/v1/sales/{document}/exchanges
     */
    public function storeExchange(StoreReturnRequest $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('createReturn', $document);

        $exchange = $this->service->createReturn(
            $request->user(),
            $document,
            $request->validated(),
            $request->ip(),
            'exchange'
        );

        return response()->json(['data' => $this->returnShape($exchange)], 201);
    }

    /**
     * GET /api/v1/returns/{return}
     */
    public function show(Request $request, ReturnRecord $return): JsonResponse
    {
        $this->authorize('view', $return->salesDocument);

        $return->load(['salesDocument']);

        return response()->json(['data' => $this->returnShape($return, withDoc: true)]);
    }

    /**
     * PUT /api/v1/returns/{return}
     */
    public function update(Request $request, ReturnRecord $return): JsonResponse
    {
        $return->loadMissing('salesDocument');
        $this->authorize('update', $return);

        $beforeHash = hash('sha256', json_encode($return->toArray()));

        $validated = $request->validate([
            'reason_detail' => ['nullable', 'string', 'max:2000'],
        ]);

        $return->update($validated);

        $this->recordAudit(
            action: AuditAction::Update,
            actorId: $request->user()->id,
            auditableId: $return->id,
            ipAddress: $request->ip(),
            beforeHash: $beforeHash,
            afterHash: hash('sha256', json_encode($return->fresh()->toArray())),
        );

        return response()->json(['data' => $this->returnShape($return->fresh())]);
    }

    /**
     * POST /api/v1/returns/{return}/complete
     */
    public function complete(CompleteReturnRequest $request, ReturnRecord $return): JsonResponse
    {
        $return->loadMissing('salesDocument');
        $this->authorize('complete', $return);

        $return = $this->service->completeReturn(
            $request->user(),
            $return,
            $request->ip()
        );

        return response()->json(['data' => $this->returnShape($return)]);
    }

    /**
     * POST /api/v1/exchanges/{return}/complete
     */
    public function completeExchange(CompleteReturnRequest $request, ReturnRecord $return): JsonResponse
    {
        $return->loadMissing('salesDocument');
        $this->authorize('complete', $return);

        if (($return->operation_type ?? 'return') !== 'exchange') {
            abort(409, 'The specified record is not an exchange.');
        }

        $exchange = $this->service->completeReturn(
            $request->user(),
            $return,
            $request->ip()
        );

        return response()->json(['data' => $this->returnShape($exchange)]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function returnShape(ReturnRecord $return, bool $withDoc = false): array
    {
        $shape = [
            'id'                     => $return->id,
            'sales_document_id'      => $return->sales_document_id,
            'return_document_number' => $return->return_document_number,
            'operation_type'         => $return->operation_type ?? 'return',
            'reason_code'            => $return->reason_code instanceof \BackedEnum
                                            ? $return->reason_code->value
                                            : $return->reason_code,
            'reason_detail'          => $return->reason_detail,
            'is_defective'           => $return->is_defective,
            'restock_fee_percent'    => $return->restock_fee_percent,
            'restock_fee_amount'     => $return->restock_fee_amount,
            'refund_amount'          => $return->refund_amount,
            'status'                 => $return->status,
            'completed_at'           => $return->completed_at?->toIso8601String(),
            'created_by'             => $return->created_by,
            'created_at'             => $return->created_at?->toIso8601String(),
            'updated_at'             => $return->updated_at?->toIso8601String(),
        ];

        if ($withDoc && $return->relationLoaded('salesDocument')) {
            $shape['sales_document'] = [
                'id'              => $return->salesDocument->id,
                'document_number' => $return->salesDocument->document_number,
                'site_code'       => $return->salesDocument->site_code,
            ];
        }

        return $shape;
    }

    private function recordAudit(
        AuditAction $action,
        ?string $actorId,
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
            action: $action,
            actorId: $actorId,
            auditableType: ReturnRecord::class,
            auditableId: $auditableId,
            beforeHash: $beforeHash,
            afterHash: $afterHash,
            payload: $payload,
            ipAddress: $ipAddress,
        );
    }
}
