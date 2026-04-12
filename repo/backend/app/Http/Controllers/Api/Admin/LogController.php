<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\StructuredLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin endpoint for browsing structured application logs.
 *
 * Supports filtering by level, channel, date range, and message search.
 * Results are ordered by recorded_at DESC.
 *
 * Authorization: admin or auditor role.
 */
class LogController extends Controller
{
    /**
     * GET /api/v1/admin/logs
     *
     * Query params:
     *   - filter.level      PSR-3 level: emergency|alert|critical|error|warning|notice|info|debug
     *   - filter.channel    Channel: application|auth|audit|backup|maintenance|workflow
     *   - filter.date_from  ISO-8601 lower bound for recorded_at (inclusive)
     *   - filter.date_to    ISO-8601 upper bound for recorded_at (inclusive)
     *   - filter.message    Substring search in the message field
     *   - filter.request_id Exact match on request correlation ID
     *   - per_page          Items per page (1–200, default 50)
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasRole(['admin', 'auditor'])) {
            abort(403, 'Admin or auditor role required to view structured logs.');
        }

        $query = StructuredLog::query();

        if ($request->has('filter.level')) {
            $query->where('level', $request->input('filter.level'));
        }

        if ($request->has('filter.channel')) {
            $query->where('channel', $request->input('filter.channel'));
        }

        if ($request->has('filter.date_from')) {
            $query->where('recorded_at', '>=', $request->input('filter.date_from'));
        }

        if ($request->has('filter.date_to')) {
            $query->where('recorded_at', '<=', $request->input('filter.date_to'));
        }

        if ($request->has('filter.message')) {
            $query->where('message', 'like', '%' . $request->input('filter.message') . '%');
        }

        if ($request->has('filter.request_id')) {
            $query->where('request_id', $request->input('filter.request_id'));
        }

        $perPage   = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->orderBy('recorded_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(StructuredLog $log) => [
                'id'             => $log->id,
                'level'          => $log->level,
                'message'        => $log->message,
                'context'        => $log->context,
                'channel'        => $log->channel,
                'request_id'     => $log->request_id,
                'recorded_at'    => $log->recorded_at?->toIso8601String(),
                'retained_until' => $log->retained_until?->toIso8601String(),
            ]),
            'meta' => [
                'retention_days' => (int) config('meridian.retention.log_days', 90),
                'pagination'     => [
                    'current_page' => $paginated->currentPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                    'last_page'    => $paginated->lastPage(),
                ],
            ],
        ]);
    }
}
