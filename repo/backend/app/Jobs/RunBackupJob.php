<?php

namespace App\Jobs;

use App\Application\Backup\BackupMetadataService;
use App\Models\BackupJob;
use App\Application\Logging\StructuredLogger;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Infrastructure\Security\EncryptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestrates the daily local backup.
 *
 * This job:
 *   1. Creates a pending BackupJob record via BackupMetadataService
 *   2. Executes mysqldump and writes a compressed .sql.gz to storage/app/backups/
 *   3. Builds a manifest (dump path, table row-count summary, attachment inventory)
 *   4. Marks the job success/failed with manifest + size_bytes
 *   5. Records a BackupRun audit event
 *
 * The backup artifact is stored locally under storage/app/backups/ using the
 * naming convention: YYYY-MM-DD-{short-job-id}.sql.gz
 *
 * Database credentials are passed to mysqldump via a temporary options file
 * (never on the command line) to avoid exposure in process listings.
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Resolved via handle() DI — stored here for use in private helpers. */
    private ?EncryptionService $encryption = null;

    public function __construct(
        private readonly bool $isManual = false,
        private readonly ?string $existingJobId = null,
    ) {}

    public function handle(
        BackupMetadataService $metadata,
        AuditEventRepositoryInterface $audit,
        StructuredLogger $logger,
        EncryptionService $encryption,
    ): void {
        // Store encryption service for use in private helpers (SerializesModels prevents constructor DI)
        $this->encryption = $encryption;

        // If a job record was pre-created by the caller (e.g. BackupController), reuse it
        // so the HTTP response always references the exact job being processed.
        // For scheduler-dispatched jobs no existingJobId is provided, so startBackup()
        // creates the record here as before.
        $job = $this->existingJobId
            ? BackupJob::findOrFail($this->existingJobId)
            : $metadata->startBackup($this->isManual);

        try {
            $metadata->markRunning($job->id);
            $logger->info('Backup started', ['job_id' => $job->id, 'is_manual' => $this->isManual], 'backup');

            $dumpPath  = $this->executeDump($job->id);
            $manifest  = $this->buildManifest($dumpPath);
            $sizeBytes = $this->estimateSizeBytes($manifest);

            $metadata->completeBackup($job->id, $manifest, $sizeBytes);
            $logger->info('Backup completed', [
                'job_id'     => $job->id,
                'size_bytes' => $sizeBytes,
                'tables'     => count($manifest['tables'] ?? []),
                'files'      => $manifest['attachment_file_count'] ?? 0,
            ], 'backup');

            $audit->record(
                correlationId:  (string) Str::uuid(),
                action:         AuditAction::BackupRun,
                actorId:        null,
                auditableType:  'BackupJob',
                auditableId:    $job->id,
                beforeHash:     null,
                afterHash:      null,
                payload:        ['status' => 'success', 'size_bytes' => $sizeBytes, 'is_manual' => $this->isManual],
                ipAddress:      '127.0.0.1',
            );
        } catch (\Throwable $e) {
            $metadata->failBackup($job->id, $e->getMessage());
            $logger->error('Backup failed', ['job_id' => $job->id, 'error' => $e->getMessage()], 'backup');

            $audit->record(
                correlationId:  (string) Str::uuid(),
                action:         AuditAction::BackupRun,
                actorId:        null,
                auditableType:  'BackupJob',
                auditableId:    $job->id,
                beforeHash:     null,
                afterHash:      null,
                payload:        ['status' => 'failed', 'error' => $e->getMessage()],
                ipAddress:      '127.0.0.1',
            );

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Database dump
    // -------------------------------------------------------------------------

    /**
     * Execute mysqldump and write a gzip-compressed dump file to storage/app/backups/.
     *
     * Uses a temporary MySQL options file for credentials to avoid exposing the
     * password in process listings (such as `ps` or procfs cmdline entries). The options file is
     * removed immediately after mysqldump completes regardless of outcome.
     *
     * @param string $jobId UUID of the BackupJob record (used in filename)
     * @return string        Absolute path to the created .sql.gz file
     *
     * @throws \RuntimeException If mysqldump exits with a non-zero code
     */
    private function executeDump(string $jobId): string
    {
        $dbHost     = config('database.connections.mysql.host', '127.0.0.1');
        $dbPort     = config('database.connections.mysql.port', 3306);
        $dbName     = config('database.connections.mysql.database');
        $dbUser     = config('database.connections.mysql.username');
        $dbPassword = config('database.connections.mysql.password', '');

        $backupDir = Storage::disk('local')->path('backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $filename = date('Y-m-d') . '-' . substr($jobId, 0, 8) . '.sql.gz';
        $dumpPath = $backupDir . '/' . $filename;

        // Write credentials to a temp options file (mode 0600 — owner-readable only)
        $optFile = tempnam(sys_get_temp_dir(), 'meridian_bk_');
        file_put_contents($optFile, implode("\n", [
            '[client]',
            'password=' . $dbPassword,
        ]));
        chmod($optFile, 0600);

        try {
            $command = sprintf(
                'mysqldump --defaults-extra-file=%s --host=%s --port=%s --user=%s'
                . ' --single-transaction --routines --triggers --skip-lock-tables %s'
                . ' | gzip > %s',
                escapeshellarg($optFile),
                escapeshellarg((string) $dbHost),
                escapeshellarg((string) $dbPort),
                escapeshellarg((string) $dbUser),
                escapeshellarg((string) $dbName),
                escapeshellarg($dumpPath),
            );

            $output     = [];
            $returnCode = 0;
            exec($command . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \RuntimeException(
                    'mysqldump exited with code ' . $returnCode . ': ' . implode(' ', $output)
                );
            }
        } finally {
            @unlink($optFile);
        }

        // HIGH-2: Encrypt the compressed dump file at rest.
        // Read the plaintext .sql.gz, encrypt it using AES-256-CBC via EncryptionService,
        // write the encrypted JSON envelope as .sql.gz.enc, then delete the plaintext artifact.
        $plaintext = file_get_contents($dumpPath);
        $envelope  = $this->encryption->encrypt($plaintext);
        $encPath   = $dumpPath . '.enc';
        file_put_contents($encPath, json_encode($envelope));
        @unlink($dumpPath);

        return $encPath;
    }

    // -------------------------------------------------------------------------
    // Manifest building
    // -------------------------------------------------------------------------

    /**
     * Build the backup manifest.
     *
     * The manifest records:
     *   - dump_file: relative storage path to the .sql.gz artifact
     *   - dump_size_bytes: compressed dump file size in bytes
     *   - tables: array of {name, row_count} for all application tables
     *   - attachment_file_count: number of attachment files in storage
     *   - attachment_storage_bytes: total byte count in attachment storage
     *   - created_at: ISO-8601 timestamp of manifest creation
     *
     * @param string $dumpPath Absolute path to the .sql.gz file
     */
    private function buildManifest(string $dumpPath): array
    {
        $dumpSizeBytes = file_exists($dumpPath) ? filesize($dumpPath) : 0;

        // Store only the filename (relative to the backups/ directory) in the manifest
        $dumpFilename = basename($dumpPath);

        return [
            'created_at'               => now()->toIso8601String(),
            'dump_file'                => 'backups/' . $dumpFilename,
            'dump_size_bytes'          => $dumpSizeBytes,
            'tables'                   => $this->collectTableStats(),
            'attachment_file_count'    => $this->countAttachmentFiles(),
            'attachment_storage_bytes' => $this->sumAttachmentStorageBytes(),
        ];
    }

    /**
     * Collect row counts for all application tables.
     */
    private function collectTableStats(): array
    {
        $tables = [
            'users', 'roles', 'departments', 'documents', 'document_versions',
            'attachments', 'attachment_links', 'audit_events', 'idempotency_keys',
            'configuration_sets', 'configuration_versions', 'canary_rollout_targets',
            'configuration_rules', 'workflow_templates', 'workflow_template_nodes',
            'workflow_instances', 'workflow_nodes', 'approvals', 'to_do_items',
            'document_number_sequences', 'sales_documents', 'sales_line_items',
            'returns', 'inventory_movements', 'backup_jobs', 'metrics_snapshots',
            'structured_logs', 'failed_login_attempts',
        ];

        $stats = [];
        foreach ($tables as $table) {
            try {
                $count = DB::table($table)->count();
                $stats[] = ['table' => $table, 'row_count' => $count];
            } catch (\Throwable) {
                $stats[] = ['table' => $table, 'row_count' => null, 'error' => 'inaccessible'];
            }
        }

        return $stats;
    }

    /**
     * Count encrypted attachment files in local storage.
     */
    private function countAttachmentFiles(): int
    {
        try {
            $files = Storage::disk('local')->allFiles('attachments');
            return count($files);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Sum total bytes of attachment files in local storage.
     */
    private function sumAttachmentStorageBytes(): int
    {
        try {
            $files = Storage::disk('local')->allFiles('attachments');
            $total = 0;
            foreach ($files as $file) {
                $total += Storage::disk('local')->size($file);
            }
            return $total;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Compute total backup size in bytes: dump artifact + attachment files.
     */
    private function estimateSizeBytes(array $manifest): int
    {
        return (int) ($manifest['dump_size_bytes'] ?? 0)
             + (int) ($manifest['attachment_storage_bytes'] ?? 0);
    }
}
