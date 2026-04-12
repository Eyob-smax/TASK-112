<?php

namespace App\Application\Backup;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Models\BackupJob;
use Illuminate\Support\Facades\Storage;

/**
 * Manages backup job lifecycle metadata in the backup_jobs table.
 *
 * This service tracks the state of backup operations but does NOT perform
 * the actual backup itself — that is the responsibility of a scheduled
 * Artisan command or queue job (implemented in a later prompt).
 */
class BackupMetadataService
{
    public function __construct(
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * Create a new backup job record in the pending state.
     *
     * Emits a Create audit event immediately after persisting the record so that
     * the initiation of every backup (scheduled or manual) is fully auditable.
     */
    public function startBackup(bool $isManual = false): BackupJob
    {
        $retentionDays = (int) config('meridian.backup.retention_days', 14);

        $job = BackupJob::create([
            'started_at'           => now(),
            'status'               => 'pending',
            'is_manual'            => $isManual,
            'retention_expires_at' => now()->addDays($retentionDays),
        ]);

        $afterHash = hash('sha256', json_encode($job->toArray()));

        $this->audit->record(
            correlationId: hash('sha256', 'backup_create:' . $job->id),
            action:        AuditAction::Create,
            actorId:       null,
            auditableType: BackupJob::class,
            auditableId:   $job->id,
            beforeHash:    null,
            afterHash:     $afterHash,
            payload:       ['status' => 'pending', 'is_manual' => $isManual],
            ipAddress:     '127.0.0.1',
        );

        return $job;
    }

    /**
     * Mark the job as actively running.
     */
    public function markRunning(string $jobId): void
    {
        $job        = BackupJob::findOrFail($jobId);
        $beforeHash = hash('sha256', json_encode($job->toArray()));
        $job->update(['status' => 'running']);
        $afterHash  = hash('sha256', json_encode($job->refresh()->toArray()));

        $this->audit->record(
            correlationId: hash('sha256', 'backup_running:' . $jobId),
            action:        AuditAction::Update,
            actorId:       null,
            auditableType: BackupJob::class,
            auditableId:   $jobId,
            beforeHash:    $beforeHash,
            afterHash:     $afterHash,
            payload:       ['status_transition' => 'pending_to_running'],
            ipAddress:     '127.0.0.1',
        );
    }

    /**
     * Mark the job as successfully completed.
     *
     * @param array $manifest  Structured list of backed-up artifacts
     * @param int   $sizeBytes Total size of the backup in bytes
     */
    public function completeBackup(string $jobId, array $manifest, int $sizeBytes): void
    {
        $job        = BackupJob::findOrFail($jobId);
        $beforeHash = hash('sha256', json_encode($job->toArray()));
        $job->update([
            'status'       => 'success',
            'manifest'     => $manifest,
            'size_bytes'   => $sizeBytes,
            'completed_at' => now(),
        ]);
        $afterHash = hash('sha256', json_encode($job->refresh()->toArray()));

        $this->audit->record(
            correlationId: hash('sha256', 'backup_complete:' . $jobId),
            action:        AuditAction::Update,
            actorId:       null,
            auditableType: BackupJob::class,
            auditableId:   $jobId,
            beforeHash:    $beforeHash,
            afterHash:     $afterHash,
            payload:       ['status_transition' => 'running_to_success', 'size_bytes' => $sizeBytes],
            ipAddress:     '127.0.0.1',
        );
    }

    /**
     * Mark the job as failed with an error message.
     */
    public function failBackup(string $jobId, string $error): void
    {
        $job        = BackupJob::findOrFail($jobId);
        $beforeHash = hash('sha256', json_encode($job->toArray()));
        $job->update([
            'status'        => 'failed',
            'error_message' => $error,
            'completed_at'  => now(),
        ]);
        $afterHash = hash('sha256', json_encode($job->refresh()->toArray()));

        $this->audit->record(
            correlationId: hash('sha256', 'backup_failed:' . $jobId),
            action:        AuditAction::Update,
            actorId:       null,
            auditableType: BackupJob::class,
            auditableId:   $jobId,
            beforeHash:    $beforeHash,
            afterHash:     $afterHash,
            payload:       ['status_transition' => 'running_to_failed'],
            ipAddress:     '127.0.0.1',
        );
    }

    /**
     * Delete backup job records whose retention window has expired.
     *
     * HIGH-3: Also deletes the physical backup artifact from local storage
     * before removing the DB row, so that physical and metadata retention
     * are always consistent (no orphaned encrypted dump files on disk).
     *
     * @return int Number of metadata rows deleted
     */
    public function pruneExpired(): int
    {
        $expiredJobs = BackupJob::where('retention_expires_at', '<', now())->get();

        $deletedCount = 0;
        foreach ($expiredJobs as $job) {
            // Delete physical backup artifact before removing metadata row
            $manifest = $job->manifest;
            if (is_array($manifest)) {
                $dumpFile = $manifest['dump_file'] ?? null;
                if ($dumpFile && Storage::disk('local')->exists($dumpFile)) {
                    Storage::disk('local')->delete($dumpFile);
                }
            }

            $beforeHash = hash('sha256', json_encode($job->toArray()));

            $this->audit->record(
                correlationId: hash('sha256', 'backup_prune:' . $job->id),
                action:        AuditAction::Delete,
                actorId:       null,
                auditableType: BackupJob::class,
                auditableId:   $job->id,
                beforeHash:    $beforeHash,
                afterHash:     $beforeHash,
                payload:       ['reason' => 'retention_expired'],
                ipAddress:     '127.0.0.1',
            );

            $job->delete();
            $deletedCount++;
        }

        return $deletedCount;
    }
}
