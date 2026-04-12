<?php

namespace App\Http\Controllers\Api;

use App\Application\Configuration\ConfigurationService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\StoreConfigurationSetRequest;
use App\Models\ConfigurationSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ConfigurationSetController extends Controller
{
    public function __construct(
        private readonly ConfigurationService $service,
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * GET /api/v1/configuration/sets
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ConfigurationSet::class);

        $user  = $request->user();
        $query = ConfigurationSet::query()->with(['department', 'createdBy']);

        // Department scope: non-admin/manager users see only their department's sets plus system-wide (null)
        if (!$user->hasRole(['admin', 'manager'])) {
            $query->where(function ($q) use ($user) {
                $q->whereNull('department_id')
                  ->orWhere('department_id', $user->department_id);
            });
        }

        if ($request->has('filter.department_id') && $user->hasRole(['admin', 'manager'])) {
            $query->where('department_id', $request->input('filter.department_id'));
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(ConfigurationSet $s) => $this->setShape($s)),
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
     * POST /api/v1/configuration/sets
     */
    public function store(StoreConfigurationSetRequest $request): JsonResponse
    {
        $set = $this->service->createSet(
            $request->user(),
            $request->validated(),
            $request->ip()
        );

        return response()->json(['data' => $this->setShape($set)], 201);
    }

    /**
     * GET /api/v1/configuration/sets/{set}
     */
    public function show(Request $request, ConfigurationSet $set): JsonResponse
    {
        $this->authorize('view', $set);

        $set->load(['department', 'createdBy', 'versions']);

        return response()->json(['data' => $this->setShape($set, withVersions: true)]);
    }

    /**
     * PUT /api/v1/configuration/sets/{set}
     */
    public function update(Request $request, ConfigurationSet $set): JsonResponse
    {
        $this->authorize('update', $set);

        $beforeHash = hash('sha256', json_encode($set->toArray()));

        $validated = $request->validate([
            'name'        => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $set->update($validated);

        $this->recordAudit(
            action: AuditAction::Update,
            actorId: $request->user()->id,
            auditableId: $set->id,
            ipAddress: $request->ip(),
            beforeHash: $beforeHash,
            afterHash: hash('sha256', json_encode($set->fresh()->toArray())),
        );

        return response()->json(['data' => $this->setShape($set->fresh()->load(['department', 'createdBy']))]);
    }

    /**
     * DELETE /api/v1/configuration/sets/{set}
     */
    public function destroy(Request $request, ConfigurationSet $set): Response
    {
        $this->authorize('update', $set);

        $beforeHash = hash('sha256', json_encode($set->toArray()));

        $set->delete();

        // On soft delete, deleted_at is applied on the in-memory model instance.
        $this->recordAudit(
            action: AuditAction::Delete,
            actorId: $request->user()->id,
            auditableId: $set->id,
            ipAddress: $request->ip(),
            beforeHash: $beforeHash,
            afterHash: hash('sha256', json_encode($set->toArray())),
        );

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function setShape(ConfigurationSet $set, bool $withVersions = false): array
    {
        $shape = [
            'id'            => $set->id,
            'name'          => $set->name,
            'description'   => $set->description,
            'department_id' => $set->department_id,
            'is_active'     => $set->is_active,
            'created_by'    => $set->created_by,
            'created_at'    => $set->created_at?->toIso8601String(),
            'updated_at'    => $set->updated_at?->toIso8601String(),
        ];

        if ($withVersions && $set->relationLoaded('versions')) {
            $shape['versions'] = $set->versions->map(fn($v) => [
                'id'             => $v->id,
                'version_number' => $v->version_number,
                'status'         => $v->status instanceof \BackedEnum ? $v->status->value : $v->status,
                'change_summary' => $v->change_summary,
                'created_at'     => $v->created_at?->toIso8601String(),
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
            auditableType: ConfigurationSet::class,
            auditableId: $auditableId,
            beforeHash: $beforeHash,
            afterHash: $afterHash,
            payload: $payload,
            ipAddress: $ipAddress,
        );
    }
}
