<?php

namespace App\Http\Controllers\Api;

use App\Application\Sales\SalesDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSalesDocumentRequest;
use App\Http\Requests\Sales\UpdateSalesDocumentRequest;
use App\Http\Requests\Sales\VoidSalesDocumentRequest;
use App\Models\SalesDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SalesDocumentController extends Controller
{
    public function __construct(
        private readonly SalesDocumentService $service,
    ) {}

    /**
     * GET /api/v1/sales
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SalesDocument::class);

        $user  = $request->user();
        $query = SalesDocument::query()->with(['department', 'createdBy']);

        // Department scope: non-elevated users see only their department
        if (!$user->hasRole(['admin', 'manager', 'auditor'])) {
            $query->where('department_id', $user->department_id);
        }

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->has('filter.department_id') && $user->hasRole(['admin', 'manager'])) {
            $query->where('department_id', $request->input('filter.department_id'));
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(SalesDocument $d) => $this->docShape($d)),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                    'last_page'    => $paginated->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/sales
     */
    public function store(StoreSalesDocumentRequest $request): JsonResponse
    {
        $doc = $this->service->create(
            $request->user(),
            $request->validated(),
            $request->ip()
        );

        return response()->json(['data' => $this->docShape($doc)], 201);
    }

    /**
     * GET /api/v1/sales/{document}
     */
    public function show(Request $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('view', $document);

        $document->load(['department', 'createdBy', 'lineItems', 'returns']);

        return response()->json(['data' => $this->docShape($document, withLineItems: true, withReturns: true)]);
    }

    /**
     * PUT /api/v1/sales/{document}
     */
    public function update(UpdateSalesDocumentRequest $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('update', $document);

        $doc = $this->service->update(
            $request->user(),
            $document,
            $request->validated(),
            $request->ip()
        );

        return response()->json(['data' => $this->docShape($doc, withLineItems: true)]);
    }

    /**
     * POST /api/v1/sales/{document}/submit
     */
    public function submit(Request $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('update', $document);

        $doc = $this->service->submit(
            $request->user(),
            $document,
            $request->ip()
        );

        return response()->json(['data' => $this->docShape($doc)]);
    }

    /**
     * POST /api/v1/sales/{document}/complete
     */
    public function complete(Request $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('complete', $document);

        $document->load('lineItems');

        $doc = $this->service->complete(
            $request->user(),
            $document,
            $request->ip()
        );

        return response()->json(['data' => $this->docShape($doc)]);
    }

    /**
     * POST /api/v1/sales/{document}/void
     */
    public function void(VoidSalesDocumentRequest $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('void', $document);

        $doc = $this->service->void(
            $request->user(),
            $document,
            $request->validated('reason'),
            $request->ip()
        );

        return response()->json(['data' => $this->docShape($doc)]);
    }

    /**
     * POST /api/v1/sales/{document}/link-outbound
     */
    public function linkOutbound(Request $request, SalesDocument $document): JsonResponse
    {
        $this->authorize('linkOutbound', $document);

        $doc = $this->service->linkOutbound(
            $request->user(),
            $document,
            $request->ip()
        );

        return response()->json(['data' => $this->docShape($doc)]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function docShape(SalesDocument $doc, bool $withLineItems = false, bool $withReturns = false): array
    {
        $shape = [
            'id'                  => $doc->id,
            'document_number'     => $doc->document_number,
            'site_code'           => $doc->site_code,
            'status'              => $doc->status instanceof \BackedEnum ? $doc->status->value : $doc->status,
            'department_id'       => $doc->department_id,
            'created_by'          => $doc->created_by,
            'reviewed_by'         => $doc->reviewed_by,
            'total_amount'        => $doc->total_amount,
            'notes'               => $doc->notes,
            'completed_at'        => $doc->completed_at?->toIso8601String(),
            'voided_at'           => $doc->voided_at?->toIso8601String(),
            'voided_reason'       => $doc->voided_reason,
            'outbound_linked_at'  => $doc->outbound_linked_at?->toIso8601String(),
            'created_at'          => $doc->created_at?->toIso8601String(),
            'updated_at'          => $doc->updated_at?->toIso8601String(),
        ];

        if ($withLineItems && $doc->relationLoaded('lineItems')) {
            $shape['line_items'] = $doc->lineItems->map(fn($item) => [
                'id'           => $item->id,
                'product_code' => $item->product_code,
                'description'  => $item->description,
                'quantity'     => $item->quantity,
                'unit_price'   => $item->unit_price,
                'line_total'   => $item->line_total,
            ])->values();
        }

        if ($withReturns && $doc->relationLoaded('returns')) {
            $shape['returns'] = $doc->returns->map(fn($r) => [
                'id'                     => $r->id,
                'return_document_number' => $r->return_document_number,
                'status'                 => $r->status,
                'reason_code'            => $r->reason_code instanceof \BackedEnum ? $r->reason_code->value : $r->reason_code,
                'return_amount'          => (float) $r->restock_fee_amount + (float) $r->refund_amount,
                'restock_fee_amount'     => $r->restock_fee_amount,
                'refund_amount'          => $r->refund_amount,
                'created_at'             => $r->created_at?->toIso8601String(),
            ])->values();
        }

        return $shape;
    }
}
