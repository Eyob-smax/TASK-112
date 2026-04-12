<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Metrics\MetricsRetentionService;
use App\Http\Controllers\Controller;
use App\Models\MetricsSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin endpoint for metrics snapshot retrieval and summary.
 *
 * Metric types:
 *   - request_timing    p95 request duration samples (ms)
 *   - queue_depth       snapshot of queued job count
 *   - failed_approvals  count of workflow nodes that expired or were rejected
 *
 * Authorization: admin role only.
 */
class MetricsController extends Controller
{
    public function __construct(
        private readonly MetricsRetentionService $metrics,
    ) {}

    /**
     * GET /api/v1/admin/metrics
     *
     * Retrieve metrics snapshots with optional filters and optional summary mode.
     *
     * Query params:
     *   - metric_type   Filter by type: request_timing | queue_depth | failed_approvals
     *   - date_from     ISO-8601 lower bound for recorded_at (inclusive)
     *   - date_to       ISO-8601 upper bound for recorded_at (inclusive)
     *   - summary       If '1' or 'true', return aggregated summary instead of raw rows
     *   - per_page      Items per page when not in summary mode (1–500, default 100)
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            abort(403, 'Admin role required to view metrics.');
        }

        $query = MetricsSnapshot::query();

        if ($request->has('metric_type')) {
            $query->where('metric_type', $request->input('metric_type'));
        }

        if ($request->has('date_from')) {
            $query->where('recorded_at', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->where('recorded_at', '<=', $request->input('date_to'));
        }

        if (filter_var($request->input('summary', false), FILTER_VALIDATE_BOOLEAN)) {
            return $this->summaryResponse($query);
        }

        $perPage   = min((int) $request->input('per_page', 100), 500);
        $paginated = $query->orderBy('recorded_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(MetricsSnapshot $s) => $this->snapshotShape($s)),
            'meta' => [
                'retention_days' => (int) config('meridian.retention.metrics_days', 90),
                'pagination'     => [
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

    /**
     * Return aggregated summary grouped by metric_type.
     */
    private function summaryResponse($query): JsonResponse
    {
        $summaries = (clone $query)
            ->select('metric_type',
                DB::raw('COUNT(*) as sample_count'),
                DB::raw('AVG(value) as avg_value'),
                DB::raw('MIN(value) as min_value'),
                DB::raw('MAX(value) as max_value'),
                DB::raw('MAX(recorded_at) as last_recorded_at')
            )
            ->groupBy('metric_type')
            ->get();

        return response()->json([
            'data' => $summaries->map(fn($row) => [
                'metric_type'     => $row->metric_type,
                'sample_count'    => (int) $row->sample_count,
                'avg_value'       => round((float) $row->avg_value, 4),
                'min_value'       => (float) $row->min_value,
                'max_value'       => (float) $row->max_value,
                'last_recorded_at' => $row->last_recorded_at,
            ]),
        ]);
    }

    private function snapshotShape(MetricsSnapshot $snapshot): array
    {
        return [
            'id'             => $snapshot->id,
            'metric_type'    => $snapshot->metric_type,
            'value'          => $snapshot->value,
            'labels'         => $snapshot->labels,
            'recorded_at'    => $snapshot->recorded_at?->toIso8601String(),
            'retained_until' => $snapshot->retained_until?->toIso8601String(),
        ];
    }
}
