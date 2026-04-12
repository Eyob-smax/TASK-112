<?php

namespace App\Http\Controllers\Api;

use App\Application\Workflow\WorkflowService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\StoreWorkflowTemplateRequest;
use App\Models\WorkflowTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class WorkflowTemplateController extends Controller
{
    public function __construct(
        private readonly WorkflowService $service,
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * GET /api/v1/workflow/templates
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkflowTemplate::class);

        $user = $request->user();

        $query = WorkflowTemplate::query()->with(['department']);

        // Non cross-scope users can only list templates from their own department.
        if (!$user->hasRole(['admin', 'manager', 'auditor'])) {
            if ($user->department_id === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('department_id', $user->department_id);
            }
        }

        if ($request->has('filter.event_type')) {
            $query->where('event_type', $request->input('filter.event_type'));
        }

        if ($request->has('filter.department_id')) {
            $query->where('department_id', $request->input('filter.department_id'));
        }

        if ($request->boolean('filter.is_active', false)) {
            $query->where('is_active', true);
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(WorkflowTemplate $t) => $this->templateShape($t)),
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
     * POST /api/v1/workflow/templates
     */
    public function store(StoreWorkflowTemplateRequest $request): JsonResponse
    {
        $validated       = $request->validated();
        $nodeDefinitions = $validated['nodes'] ?? [];
        unset($validated['nodes']);

        $template = $this->service->createTemplate(
            $request->user(),
            $validated,
            $nodeDefinitions,
            $request->ip()
        );

        return response()->json(['data' => $this->templateShape($template, withNodes: true)], 201);
    }

    /**
     * GET /api/v1/workflow/templates/{template}
     */
    public function show(Request $request, WorkflowTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);

        $template->load(['department', 'nodes']);

        return response()->json(['data' => $this->templateShape($template, withNodes: true)]);
    }

    /**
     * PUT /api/v1/workflow/templates/{template}
     */
    public function update(Request $request, WorkflowTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);

        $beforeHash = hash('sha256', json_encode($template->toArray()));

        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active'   => ['sometimes', 'boolean'],
        ]);

        $template->update($validated);

        $this->recordAudit(
            action: AuditAction::Update,
            actorId: $request->user()->id,
            auditableId: $template->id,
            ipAddress: $request->ip(),
            beforeHash: $beforeHash,
            afterHash: hash('sha256', json_encode($template->fresh()->toArray())),
        );

        return response()->json(['data' => $this->templateShape($template->fresh()->load(['department']))]);
    }

    /**
     * DELETE /api/v1/workflow/templates/{template}
     */
    public function destroy(Request $request, WorkflowTemplate $template): Response
    {
        $this->authorize('delete', $template);

        $beforeHash = hash('sha256', json_encode($template->toArray()));

        $template->delete();

        $this->recordAudit(
            action: AuditAction::Delete,
            actorId: $request->user()->id,
            auditableId: $template->id,
            ipAddress: $request->ip(),
            beforeHash: $beforeHash,
            afterHash: hash('sha256', json_encode($template->toArray())),
        );

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function templateShape(WorkflowTemplate $template, bool $withNodes = false): array
    {
        $shape = [
            'id'                    => $template->id,
            'name'                  => $template->name,
            'description'           => $template->description,
            'event_type'            => $template->event_type,
            'department_id'         => $template->department_id,
            'amount_threshold_min'  => $template->amount_threshold_min,
            'amount_threshold_max'  => $template->amount_threshold_max,
            'is_active'             => $template->is_active,
            'created_by'            => $template->created_by,
            'created_at'            => $template->created_at?->toIso8601String(),
            'updated_at'            => $template->updated_at?->toIso8601String(),
        ];

        if ($withNodes && $template->relationLoaded('nodes')) {
            $shape['nodes'] = $template->nodes->map(fn($n) => [
                'id'                 => $n->id,
                'node_type'          => $n->node_type instanceof \BackedEnum ? $n->node_type->value : $n->node_type,
                'node_order'         => $n->node_order,
                'role_required'      => $n->role_required,
                'user_required'      => $n->user_required,
                'sla_business_days'  => $n->sla_business_days,
                'is_parallel'        => $n->is_parallel,
                'condition_field'    => $n->condition_field,
                'condition_operator' => $n->condition_operator,
                'condition_value'    => $n->condition_value,
                'label'              => $n->label,
            ])->values();
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
            auditableType: WorkflowTemplate::class,
            auditableId: $auditableId,
            beforeHash: $beforeHash,
            afterHash: $afterHash,
            payload: $payload,
            ipAddress: $ipAddress,
        );
    }
}
