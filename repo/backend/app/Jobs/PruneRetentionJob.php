<?php

namespace App\Jobs;

use App\Application\Logging\StructuredLogger;
use App\Application\Metrics\MetricsRetentionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Prunes structured logs and metrics snapshots beyond the 90-day retention window.
 *
 * Scheduled daily. Calls:
 *   - StructuredLogger::prune()        → deletes structured_logs rows where retained_until < now()
 *   - MetricsRetentionService::pruneExpired() → deletes metrics_snapshots rows where retained_until < now()
 */
class PruneRetentionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(StructuredLogger $logger, MetricsRetentionService $metrics): void
    {
        $deletedLogs    = $logger->prune();
        $deletedMetrics = $metrics->pruneExpired();

        // Write result AFTER pruning so the record survives
        $logger->info('Retention pruning completed', [
            'deleted_logs'    => $deletedLogs,
            'deleted_metrics' => $deletedMetrics,
        ], 'maintenance');
    }
}
