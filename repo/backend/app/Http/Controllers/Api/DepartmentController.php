<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DepartmentController extends Controller
{
    public function __construct(
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * GET /api/v1/departments
     *
     * List all active departments for authorized users.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        $departments = Department::where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $departments->map(fn($d) => $this->deptShape($d))->values(),
        ]);
    }

    /**
     * GET /api/v1/departments/{department}
     *
     * Get a single department.
     */
    public function show(Request $request, Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        return response()->json(['data' => $this->deptShape($department)]);
    }

    /**
     * POST /api/v1/departments
     *
     * Create a department.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Department::class);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['required', 'string', 'max:20', 'unique:departments,code'],
            'description' => ['nullable', 'string'],
            'parent_id'   => ['nullable', 'uuid', 'exists:departments,id'],
        ]);

        $dept = Department::create($validated);

        $this->recordAudit(
            action: AuditAction::Create,
            actorId: $request->user()->id,
            auditableId: $dept->id,
            ipAddress: $request->ip(),
            afterHash: hash('sha256', json_encode($dept->toArray())),
        );

        return response()->json(['data' => $this->deptShape($dept)], 201);
    }

    /**
     * PUT /api/v1/departments/{department}
     *
     * Update a department.
     */
    public function update(Request $request, Department $department): JsonResponse
    {
        $this->authorize('update', $department);

        $beforeHash = hash('sha256', json_encode($department->toArray()));

        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'code'        => ['sometimes', 'string', 'max:20', 'unique:departments,code,' . $department->id],
            'description' => ['nullable', 'string'],
            'parent_id'   => ['nullable', 'uuid', 'exists:departments,id'],
        ]);

        $department->update($validated);

        $this->recordAudit(
            action: AuditAction::Update,
            actorId: $request->user()->id,
            auditableId: $department->id,
            ipAddress: $request->ip(),
            beforeHash: $beforeHash,
            afterHash: hash('sha256', json_encode($department->fresh()->toArray())),
        );

        return response()->json(['data' => $this->deptShape($department->fresh())]);
    }

    /**
     * DELETE /api/v1/departments/{department}
     *
     * Deactivate a department (soft).
     */
    public function destroy(Request $request, Department $department): \Illuminate\Http\Response
    {
        $this->authorize('delete', $department);

        $beforeHash = hash('sha256', json_encode($department->toArray()));

        $department->update(['is_active' => false]);

        $this->recordAudit(
            action: AuditAction::Update,
            actorId: $request->user()->id,
            auditableId: $department->id,
            ipAddress: $request->ip(),
            payload: ['deactivated' => true],
            beforeHash: $beforeHash,
            afterHash: hash('sha256', json_encode($department->fresh()->toArray())),
        );

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function deptShape(Department $d): array
    {
        return [
            'id'          => $d->id,
            'name'        => $d->name,
            'code'        => $d->code,
            'description' => $d->description,
            'parent_id'   => $d->parent_id,
            'is_active'   => $d->is_active,
        ];
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
            auditableType: Department::class,
            auditableId: $auditableId,
            beforeHash: $beforeHash,
            afterHash: $afterHash,
            payload: $payload,
            ipAddress: $ipAddress,
        );
    }
}
