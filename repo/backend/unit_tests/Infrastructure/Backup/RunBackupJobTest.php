<?php

use App\Application\Backup\BackupMetadataService;
use App\Application\Logging\StructuredLogger;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Infrastructure\Security\EncryptionService;
use App\Jobs\RunBackupJob;
use App\Models\AuditEvent;
use App\Models\BackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| RunBackupJob — Integration Tests
|--------------------------------------------------------------------------
| Exercises the full orchestration: mysqldump execution, manifest build,
| encryption envelope, audit trail, and failure semantics.
|
| The test container has mysqldump + gzip available and a live MySQL, so
| we run the job end-to-end rather than mocking the shell command.
*/

uses(RefreshDatabase::class);

// Keep Storage isolated so tests don't pollute the real backups/ directory.
beforeEach(function () {
    Storage::fake('local');
});

// -------------------------------------------------------------------------
// Happy path — scheduler-dispatched (no pre-existing job id)
// -------------------------------------------------------------------------

it('RunBackupJob creates a BackupJob record, writes an encrypted dump, and records BackupRun audit event (success)', function () {
    (new RunBackupJob(isManual: false))->handle(
        app(BackupMetadataService::class),
        app(AuditEventRepositoryInterface::class),
        app(StructuredLogger::class),
        app(EncryptionService::class),
    );

    $job = BackupJob::first();
    expect($job)->not->toBeNull()
        ->and($job->status)->toBe('success')
        ->and($job->is_manual)->toBeFalse()
        ->and($job->completed_at)->not->toBeNull()
        ->and($job->size_bytes)->toBeGreaterThan(0);

    // Manifest must carry the expected structure
    $manifest = $job->manifest;
    expect($manifest)->toBeArray()
        ->and($manifest)->toHaveKeys(['created_at', 'dump_file', 'dump_size_bytes', 'tables', 'attachment_file_count', 'attachment_storage_bytes'])
        ->and($manifest['tables'])->toBeArray()->not->toBeEmpty();

    // Encrypted artifact must exist on disk (not the plaintext .sql.gz)
    $encFiles = Storage::disk('local')->files('backups');
    $encArtifact = collect($encFiles)->first(fn ($f) => str_ends_with($f, '.sql.gz.enc'));
    expect($encArtifact)->not->toBeNull();

    // The plaintext .sql.gz must have been removed
    $plaintext = collect($encFiles)->first(fn ($f) => str_ends_with($f, '.sql.gz'));
    expect($plaintext)->toBeNull();

    // A BackupRun audit event must be recorded with status=success
    $auditEvent = AuditEvent::where('auditable_id', $job->id)
        ->where('action', AuditAction::BackupRun->value)
        ->first();
    expect($auditEvent)->not->toBeNull()
        ->and($auditEvent->payload['status'])->toBe('success')
        ->and($auditEvent->payload['is_manual'])->toBeFalse();
});

// -------------------------------------------------------------------------
// Manual flag propagation
// -------------------------------------------------------------------------

it('RunBackupJob propagates is_manual=true into the BackupJob record and audit payload', function () {
    (new RunBackupJob(isManual: true))->handle(
        app(BackupMetadataService::class),
        app(AuditEventRepositoryInterface::class),
        app(StructuredLogger::class),
        app(EncryptionService::class),
    );

    $job = BackupJob::first();
    expect($job->is_manual)->toBeTrue();

    $auditEvent = AuditEvent::where('auditable_id', $job->id)
        ->where('action', AuditAction::BackupRun->value)
        ->first();
    expect($auditEvent->payload['is_manual'])->toBeTrue();
});

// -------------------------------------------------------------------------
// Existing job id reuse (HTTP-initiated dispatch)
// -------------------------------------------------------------------------

it('RunBackupJob reuses an existingJobId instead of creating a new record', function () {
    $pre = BackupJob::create([
        'started_at'           => now(),
        'status'               => 'pending',
        'is_manual'            => true,
        'retention_expires_at' => now()->addDays(14),
    ]);

    (new RunBackupJob(isManual: true, existingJobId: $pre->id))->handle(
        app(BackupMetadataService::class),
        app(AuditEventRepositoryInterface::class),
        app(StructuredLogger::class),
        app(EncryptionService::class),
    );

    // Only one record exists — the pre-created one, now marked success
    expect(BackupJob::count())->toBe(1);
    expect(BackupJob::find($pre->id)->status)->toBe('success');
});

// -------------------------------------------------------------------------
// Failure path — existingJobId that doesn't exist throws ModelNotFoundException.
// The try/catch inside handle() only fires AFTER the job resolution, so this
// additionally documents the contract of pre-validated job ids.
// -------------------------------------------------------------------------

it('RunBackupJob throws when existingJobId does not resolve to a BackupJob', function () {
    expect(fn () => (new RunBackupJob(existingJobId: '00000000-0000-0000-0000-000000000000'))->handle(
        app(BackupMetadataService::class),
        app(AuditEventRepositoryInterface::class),
        app(StructuredLogger::class),
        app(EncryptionService::class),
    ))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    // No BackupJob row should have been created because resolution failed up-front
    expect(BackupJob::count())->toBe(0);
});

// -------------------------------------------------------------------------
// Manifest table stats
// -------------------------------------------------------------------------

it('RunBackupJob manifest collects row counts for known application tables', function () {
    (new RunBackupJob())->handle(
        app(BackupMetadataService::class),
        app(AuditEventRepositoryInterface::class),
        app(StructuredLogger::class),
        app(EncryptionService::class),
    );

    $manifest = BackupJob::first()->manifest;
    $tableNames = collect($manifest['tables'])->pluck('table')->all();

    expect($tableNames)->toContain('users', 'audit_events', 'backup_jobs', 'attachments');
});
