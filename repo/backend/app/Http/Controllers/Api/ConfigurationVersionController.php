<?php

namespace App\Http\Controllers\Api;

use App\Application\Configuration\ConfigurationService;
use App\Exceptions\Configuration\CanaryStoreCountMisconfiguredException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuration\RolloutConfigurationVersionRequest;
use App\Http\Requests\Configuration\StoreConfigurationVersionRequest;
use App\Models\ConfigurationSet;
use App\Models\ConfigurationVersion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ConfigurationVersionController extends Controller
{
    public function __construct(
        private readonly ConfigurationService $service,
    ) {}

    /**
     * GET /api/v1/configuration/sets/{set}/versions
     */
    public function index(Request $request, ConfigurationSet $set): JsonResponse
    {
        $this->authorize('view', $set);

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $set->versions()
            ->with(['rules'])
            ->orderBy('version_number', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(ConfigurationVersion $v) => $this->versionShape($v)),
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
     * POST /api/v1/configuration/sets/{set}/versions
     */
    public function store(StoreConfigurationVersionRequest $request, ConfigurationSet $set): JsonResponse
    {
        $this->authorize('update', $set);

        $version = $this->service->createVersion(
            $request->user(),
            $set,
            $request->validated(),
            $request->ip()
        );

        return response()->json(['data' => $this->versionShape($version)], 201);
    }

    /**
     * GET /api/v1/configuration/versions/{version}
     */
    public function show(Request $request, ConfigurationVersion $version): JsonResponse
    {
        $this->authorize('view', $version->configurationSet);

        $version->load(['rules', 'configurationSet', 'canaryTargets']);

        return response()->json(['data' => $this->versionShape($version, withDetails: true)]);
    }

    /**
     * POST /api/v1/configuration/versions/{version}/rollout
     */
    public function rollout(RolloutConfigurationVersionRequest $request, ConfigurationVersion $version): JsonResponse
    {
        $this->authorize('manageRollout', $version->configurationSet);

        $validated = $request->validated();
        $targetIds = array_values(array_unique($validated['target_ids']));

        // Eligible targets are resolved server-side and every submitted target ID
        // must exist in that authoritative set.
        [$eligibleTargets, $eligibleCount] = match ($validated['target_type']) {
            'user'  => $this->resolveEligibleUserTargets($request->user(), $version->configurationSet),
            'store' => $this->resolveEligibleStoreTargets(),
            default => [[], 0],
        };

        $this->assertTargetIdsAreEligible($targetIds, $eligibleTargets);

        $version = $this->service->startCanaryRollout(
            $request->user(),
            $version,
            $validated['target_type'],
            $targetIds,
            $eligibleCount,
            $request->ip()
        );

        return response()->json(['data' => $this->versionShape($version)]);
    }

    /**
     * POST /api/v1/configuration/versions/{version}/promote
     */
    public function promote(Request $request, ConfigurationVersion $version): JsonResponse
    {
        $this->authorize('manageRollout', $version->configurationSet);

        $version = $this->service->promoteVersion(
            $request->user(),
            $version,
            $request->ip()
        );

        return response()->json(['data' => $this->versionShape($version)]);
    }

    /**
     * POST /api/v1/configuration/versions/{version}/rollback
     */
    public function rollback(Request $request, ConfigurationVersion $version): JsonResponse
    {
        $this->authorize('manageRollout', $version->configurationSet);

        $version = $this->service->rollbackVersion(
            $request->user(),
            $version,
            $request->ip()
        );

        return response()->json(['data' => $this->versionShape($version)]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function versionShape(ConfigurationVersion $version, bool $withDetails = false): array
    {
        $shape = [
            'id'                   => $version->id,
            'configuration_set_id' => $version->configuration_set_id,
            'version_number'       => $version->version_number,
            'status'               => $version->status instanceof \BackedEnum
                                        ? $version->status->value
                                        : $version->status,
            'payload'              => $version->payload,
            'change_summary'       => $version->change_summary,
            'canary_started_at'    => $version->canary_started_at?->toIso8601String(),
            'promoted_at'          => $version->promoted_at?->toIso8601String(),
            'rolled_back_at'       => $version->rolled_back_at?->toIso8601String(),
            'created_at'           => $version->created_at?->toIso8601String(),
            'updated_at'           => $version->updated_at?->toIso8601String(),
        ];

        if ($withDetails) {
            if ($version->relationLoaded('rules')) {
                $shape['rules'] = $version->rules->map(fn($r) => [
                    'id'          => $r->id,
                    'rule_type'   => $r->rule_type instanceof \BackedEnum ? $r->rule_type->value : $r->rule_type,
                    'rule_key'    => $r->rule_key,
                    'rule_value'  => $r->rule_value,
                    'is_active'   => $r->is_active,
                    'priority'    => $r->priority,
                    'description' => $r->description,
                ])->values();
            }

            if ($version->relationLoaded('canaryTargets')) {
                $shape['canary_targets'] = $version->canaryTargets->map(fn($t) => [
                    'id'          => $t->id,
                    'target_type' => $t->target_type instanceof \BackedEnum ? $t->target_type->value : $t->target_type,
                    'target_id'   => $t->target_id,
                ])->values();
            }
        }

        return $shape;
    }

    private function resolveEligibleUserTargets(User $actor, ConfigurationSet $set): array
    {
        $query = User::query()->where('is_active', true);

        if (
            !$actor->hasRole(['admin', 'manager'])
            && $set->department_id !== null
        ) {
            $query->where('department_id', $set->department_id);
        }

        $eligibleUserIds = $query->pluck('id')->all();

        return [$eligibleUserIds, count($eligibleUserIds)];
    }

    private function resolveEligibleStoreTargets(): array
    {
        $storeIds = config('meridian.canary.store_ids', []);

        if (!is_array($storeIds)) {
            $storeIds = [];
        }

        $storeIds = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $storeIds
        ))));

        $storeCount = (int) config('meridian.canary.store_count', 0);

        if ($storeCount <= 0 || count($storeIds) === 0 || count($storeIds) !== $storeCount) {
            throw new CanaryStoreCountMisconfiguredException();
        }

        return [$storeIds, $storeCount];
    }

    private function assertTargetIdsAreEligible(array $targetIds, array $eligibleIds): void
    {
        $eligibleLookup = array_fill_keys($eligibleIds, true);
        $invalid = [];

        foreach ($targetIds as $id) {
            if (!isset($eligibleLookup[$id])) {
                $invalid[] = $id;
            }
        }

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'target_ids' => ['One or more target_ids are not eligible for canary rollout.'],
            ]);
        }
    }
}
