<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provides immutable, read-only access to the audit event log.
 *
 * Authorization: admin and auditor roles only (enforced by AuditEventPolicy).
 */
class AuditEventController extends Controller
{
    /**
     * GET /api/v1/audit/events
     *
     * Browse audit events with optional filters. Results are ordered by
     * created_at DESC so the most recent events appear first.
     *
     * Filters (all optional):
     *   - filter.actor_id         UUID of the acting user
     *   - filter.action           Exact action string (e.g. 'login', 'approve')
     *   - filter.auditable_type   Model class name (e.g. 'App\Models\SalesDocument')
     *   - filter.auditable_id     UUID of the affected record
     *   - filter.date_from        ISO-8601 lower bound (inclusive)
     *   - filter.date_to          ISO-8601 upper bound (inclusive)
     *   - per_page                Items per page (1–200, default 50)
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditEvent::class);

        $query = AuditEvent::query();

        if ($request->has('filter.actor_id')) {
            $query->where('actor_id', $request->input('filter.actor_id'));
        }

        if ($request->has('filter.action')) {
            $query->where('action', $request->input('filter.action'));
        }

        if ($request->has('filter.auditable_type')) {
            $query->where('auditable_type', $request->input('filter.auditable_type'));
        }

        if ($request->has('filter.auditable_id')) {
            $query->where('auditable_id', $request->input('filter.auditable_id'));
        }

        if ($request->has('filter.date_from')) {
            $query->where('created_at', '>=', $request->input('filter.date_from'));
        }

        if ($request->has('filter.date_to')) {
            $query->where('created_at', '<=', $request->input('filter.date_to'));
        }

        $perPage   = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(AuditEvent $e) => $this->eventShape($e)),
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
     * GET /api/v1/audit/events/{event}
     *
     * Retrieve a single audit event by its UUID.
     */
    public function show(Request $request, AuditEvent $event): JsonResponse
    {
        $this->authorize('view', $event);

        return response()->json(['data' => $this->eventShape($event)]);
    }

    /**
     * GET /api/v1/admin/config-promotions
     *
     * Filtered audit view: configuration rollout and promotion events.
     * Returns events with action in [rollout_start, rollout_promote, rollout_back].
     *
     * Query params:
     *   - filter.auditable_id   UUID of specific ConfigurationVersion
     *   - filter.date_from      ISO-8601 lower bound
     *   - filter.date_to        ISO-8601 upper bound
     *   - per_page              Items per page (default 50, max 200)
     */
    public function configPromotions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', \App\Models\AuditEvent::class);

        $query = AuditEvent::query()
            ->whereIn('action', ['rollout_start', 'rollout_promote', 'rollout_back']);

        if ($request->has('filter.auditable_id')) {
            $query->where('auditable_id', $request->input('filter.auditable_id'));
        }

        if ($request->has('filter.date_from')) {
            $query->where('created_at', '>=', $request->input('filter.date_from'));
        }

        if ($request->has('filter.date_to')) {
            $query->where('created_at', '<=', $request->input('filter.date_to'));
        }

        $perPage   = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(AuditEvent $e) => $this->eventShape($e)),
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function eventShape(AuditEvent $event): array
    {
        return [
            'id'             => $event->id,
            'correlation_id' => $event->correlation_id,
            'actor_id'       => $event->actor_id,
            'action'         => $event->action instanceof \BackedEnum ? $event->action->value : $event->action,
            'auditable_type' => $event->auditable_type,
            'auditable_id'   => $event->auditable_id,
            'before_hash'    => $event->before_hash,
            'after_hash'     => $event->after_hash,
            'payload'        => $event->payload,
            'ip_address'     => $event->ip_address,
            'created_at'     => $event->created_at?->toIso8601String(),
        ];
    }
}
