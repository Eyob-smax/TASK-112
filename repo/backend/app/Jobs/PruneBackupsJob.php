<?php

namespace App\Jobs;

use App\Application\Backup\BackupMetadataService;
use App\Application\Logging\StructuredLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Prunes backup job records whose retention window (14 days) has expired.
 *
 * Scheduled daily. Delegates deletion to BackupMetadataService::pruneExpired()
 * which deletes rows from backup_jobs where retention_expires_at < now().
 */
class PruneBackupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BackupMetadataService $metadata, StructuredLogger $logger): void
    {
        $deleted = $metadata->pruneExpired();

        $logger->info('Backup retention pruning completed', [
            'deleted_count' => $deleted,
        ], 'maintenance');
    }
}
