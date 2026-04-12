<?php

namespace App\Http\Controllers\Api\Admin;

use App\Application\Backup\BackupMetadataService;
use App\Http\Controllers\Controller;
use App\Jobs\RunBackupJob;
use App\Models\BackupJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin endpoint for backup history and manual backup triggers.
 *
 * Authorization: admin role only.
 */
class BackupController extends Controller
{
    public function __construct(
        private readonly BackupMetadataService $metadata,
    ) {}

    /**
     * GET /api/v1/admin/backups
     *
     * List backup history ordered by started_at DESC, with retention status.
     *
     * Query params:
     *   - status    Filter by status: pending | running | success | failed
     *   - per_page  Items per page (1–100, default 25)
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            abort(403, 'Admin role required to view backup history.');
        }

        $query = BackupJob::query();

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->orderBy('started_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(BackupJob $job) => $this->jobShape($job)),
            'meta' => [
                'retention_days' => (int) config('meridian.backup.retention_days', 14),
                'pagination'     => [
                    'current_page' => $paginated->currentPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                    'last_page'    => $paginated->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/backups
     *
     * Trigger an immediate manual backup.
     *
     * The job is dispatched to the queue synchronously so the response carries
     * the created BackupJob record. The actual manifest completion happens
     * within the queued job.
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            abort(403, 'Admin role required to trigger backups.');
        }

        // Create the job record synchronously so the response always references
        // the exact job being queued — avoids returning a stale/unrelated record
        // under concurrent admin activity.
        $job = $this->metadata->startBackup(true);

        RunBackupJob::dispatch(true, $job->id); // isManual=true, existingJobId=$job->id

        return response()->json(['data' => $this->jobShape($job)], 202);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function jobShape(BackupJob $job): array
    {
        return [
            'id'                   => $job->id,
            'status'               => $job->status,
            'is_manual'            => $job->is_manual,
            'started_at'           => $job->started_at?->toIso8601String(),
            'completed_at'         => $job->completed_at?->toIso8601String(),
            'size_bytes'           => $job->size_bytes,
            'manifest'             => $job->manifest,
            'error_message'        => $job->error_message,
            'retention_expires_at' => $job->retention_expires_at?->toIso8601String(),
            'created_at'           => $job->created_at?->toIso8601String(),
        ];
    }
}
