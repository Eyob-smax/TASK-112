<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Local operational health endpoint for offline administration.
 *
 * Returns:
 *   - Database connectivity status
 *   - Queue depth (pending jobs count)
 *   - Local disk space (attachment storage)
 *   - Last backup metadata
 *   - Application version and environment
 *
 * Authorization: admin role only.
 * This endpoint intentionally does NOT hit any external systems.
 */
class HealthController extends Controller
{
    /**
     * GET /api/v1/admin/health
     */
    public function index(Request $request): JsonResponse
    {
        if (!$request->user()->hasRole('admin')) {
            abort(403, 'Admin role required to view health status.');
        }

        $checks = [
            'database'  => $this->checkDatabase(),
            'queue'     => $this->checkQueue(),
            'storage'   => $this->checkStorage(),
            'backup'    => $this->checkLastBackup(),
        ];

        $allHealthy = collect($checks)->every(fn($c) => $c['status'] === 'ok');

        return response()->json([
            'data' => [
                'status'     => $allHealthy ? 'ok' : 'degraded',
                'checks'     => $checks,
                'app'        => [
                    'version'     => config('app.version', '7.0.0'),
                    'environment' => config('app.env'),
                    'timezone'    => config('app.timezone'),
                    'lan_base_url' => config('meridian.lan_base_url'),
                ],
                'retention'  => [
                    'backup_days'  => (int) config('meridian.backup.retention_days', 14),
                    'log_days'     => (int) config('meridian.retention.log_days', 90),
                    'metrics_days' => (int) config('meridian.retention.metrics_days', 90),
                ],
                'checked_at' => now()->toIso8601String(),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Private health checks
    // -------------------------------------------------------------------------

    private function checkDatabase(): array
    {
        try {
            DB::connection()->getPdo();
            return ['status' => 'ok', 'detail' => 'Connected'];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed  = DB::table('failed_jobs')->count();

            return [
                'status'         => 'ok',
                'pending_jobs'   => $pending,
                'failed_jobs'    => $failed,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    private function checkStorage(): array
    {
        try {
            $files = Storage::disk('local')->allFiles('attachments');
            $total = 0;
            foreach ($files as $file) {
                $total += Storage::disk('local')->size($file);
            }

            return [
                'status'             => 'ok',
                'attachment_files'   => count($files),
                'attachment_bytes'   => $total,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'fail', 'detail' => $e->getMessage()];
        }
    }

    private function checkLastBackup(): array
    {
        $last = BackupJob::query()
            ->where('status', 'success')
            ->orderBy('started_at', 'desc')
            ->first();

        if ($last === null) {
            return ['status' => 'warn', 'detail' => 'No successful backup found.'];
        }

        $hoursAgo = $last->started_at->diffInHours(now());

        return [
            'status'            => $hoursAgo <= 26 ? 'ok' : 'warn',
            'last_backup_at'    => $last->started_at->toIso8601String(),
            'hours_ago'         => $hoursAgo,
            'size_bytes'        => $last->size_bytes,
            'expires_at'        => $last->retention_expires_at?->toIso8601String(),
        ];
    }
}
