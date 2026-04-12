<?php

namespace App\Jobs;

use App\Application\Metrics\MetricsRetentionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Snapshots the current database queue depth as a `queue_depth` metrics record.
 *
 * Scheduled to run every 5 minutes. Queries the `jobs` table directly — valid
 * for the database queue driver used in this offline single-host deployment.
 */
class RecordQueueDepthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MetricsRetentionService $metrics): void
    {
        $depth = (float) DB::table('jobs')->count();

        $metrics->record('queue_depth', $depth, [
            'driver' => 'database',
        ]);
    }
}
