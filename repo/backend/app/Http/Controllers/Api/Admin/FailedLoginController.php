<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FailedLoginAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin endpoint for inspecting failed login attempts and locked accounts.
 *
 * Provides visibility into:
 *   - Recent failed login attempt records
 *   - Currently locked accounts (locked_until > now())
 *
 * Authorization: admin role only (security-sensitive data).
 */
class FailedLoginController extends Controller
{
    /**
     * GET /api/v1/admin/failed-logins
     *
     * Browse failed login attempt records.
     *
     * Query params:
     *   - filter.user_id    UUID — filter attempts for a specific user
     *   - filter.ip_address Exact IP address match
     *   - filter.date_from  ISO-8601 lower bound for attempted_at (inclusive)
     *   - filter.date_to    ISO-8601 upper bound for attempted_at (inclusive)
     *   - per_page          Items per page (1–200, default 50)
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            abort(403, 'Admin role required to view failed login attempts.');
        }

        $query = FailedLoginAttempt::query();

        if ($request->has('filter.user_id')) {
            $query->where('user_id', $request->input('filter.user_id'));
        }

        if ($request->has('filter.ip_address')) {
            $query->where('ip_address', $request->input('filter.ip_address'));
        }

        if ($request->has('filter.date_from')) {
            $query->where('attempted_at', '>=', $request->input('filter.date_from'));
        }

        if ($request->has('filter.date_to')) {
            $query->where('attempted_at', '<=', $request->input('filter.date_to'));
        }

        $perPage   = min((int) $request->input('per_page', 50), 200);
        $paginated = $query->orderBy('attempted_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(FailedLoginAttempt $a) => [
                'id'                  => $a->id,
                'user_id'             => $a->user_id,
                'username_attempted'  => $a->username_attempted,
                'ip_address'          => $a->ip_address,
                'attempted_at'        => $a->attempted_at?->toIso8601String(),
            ]),
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
     * GET /api/v1/admin/locked-accounts
     *
     * List user accounts that are currently locked (locked_until > now()).
     *
     * Returns user summaries including locked_until, failed_attempt_count.
     */
    public function lockedAccounts(Request $request): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            abort(403, 'Admin role required to view locked accounts.');
        }

        $locked = User::query()
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->orderBy('locked_until', 'desc')
            ->get();

        return response()->json([
            'data' => $locked->map(fn(User $u) => [
                'id'                   => $u->id,
                'username'             => $u->username,
                'email'                => $u->email,
                'display_name'         => $u->display_name,
                'locked_until'         => $u->locked_until?->toIso8601String(),
                'failed_attempt_count' => $u->failed_attempt_count,
                'last_failed_at'       => $u->last_failed_at?->toIso8601String(),
            ]),
        ]);
    }
}
