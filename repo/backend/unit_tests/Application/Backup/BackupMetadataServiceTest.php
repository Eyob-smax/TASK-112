<?php

use App\Application\Backup\BackupMetadataService;
use App\Models\BackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| BackupMetadataService — Unit Tests
|--------------------------------------------------------------------------
| Covers: job creation, lifecycle transitions, 14-day retention pruning.
| Uses RefreshDatabase to isolate DB state between tests.
*/

uses(RefreshDatabase::class);

it('creates a backup job in pending status with correct retention expiry', function () {
    $service = app(BackupMetadataService::class);

    $job = $service->startBackup(false);

    expect($job->status)->toBe('pending')
        ->and($job->is_manual)->toBeFalse()
        ->and($job->started_at)->not->toBeNull()
        ->and($job->retention_expires_at)->not->toBeNull();

    $retentionDays = (int) config('meridian.backup.retention_days', 14);
    $expected      = now()->addDays($retentionDays)->startOfMinute();
    $actual        = $job->retention_expires_at->startOfMinute();
    expect($actual->equalTo($expected))->toBeTrue();
});

it('creates a manual backup job with is_manual=true', function () {
    $service = app(BackupMetadataService::class);

    $job = $service->startBackup(true);

    expect($job->is_manual)->toBeTrue()
        ->and($job->status)->toBe('pending');
});

it('transitions job to running status', function () {
    $service = app(BackupMetadataService::class);
    $job     = $service->startBackup(false);

    $service->markRunning($job->id);

    $job->refresh();
    expect($job->status)->toBe('running');
});

it('completes a backup job with manifest and size_bytes', function () {
    $service  = app(BackupMetadataService::class);
    $job      = $service->startBackup(false);
    $manifest = ['tables' => [['table' => 'users', 'row_count' => 5]], 'attachment_file_count' => 3];

    $service->completeBackup($job->id, $manifest, 1024 * 1024);

    $job->refresh();
    expect($job->status)->toBe('success')
        ->and($job->manifest)->toBeArray()
        ->and($job->manifest['attachment_file_count'])->toBe(3)
        ->and($job->size_bytes)->toBe(1024 * 1024)
        ->and($job->completed_at)->not->toBeNull();
});

it('marks a backup job as failed with an error message', function () {
    $service = app(BackupMetadataService::class);
    $job     = $service->startBackup(false);

    $service->failBackup($job->id, 'Disk full during backup');

    $job->refresh();
    expect($job->status)->toBe('failed')
        ->and($job->error_message)->toBe('Disk full during backup')
        ->and($job->completed_at)->not->toBeNull();
});

it('prunes backup records whose retention window has expired', function () {
    $service = app(BackupMetadataService::class);

    // Create two jobs: one expired, one still within retention
    BackupJob::create([
        'started_at'           => now()->subDays(20),
        'status'               => 'success',
        'retention_expires_at' => now()->subDays(6),  // expired
        'is_manual'            => false,
    ]);

    BackupJob::create([
        'started_at'           => now()->subDays(5),
        'status'               => 'success',
        'retention_expires_at' => now()->addDays(9),  // still within retention
        'is_manual'            => false,
    ]);

    $deleted = $service->pruneExpired();

    expect($deleted)->toBe(1)
        ->and(BackupJob::count())->toBe(1);
});

it('returns zero from pruneExpired when no records have expired', function () {
    $service = app(BackupMetadataService::class);

    BackupJob::create([
        'started_at'           => now(),
        'status'               => 'success',
        'retention_expires_at' => now()->addDays(14),
        'is_manual'            => false,
    ]);

    expect($service->pruneExpired())->toBe(0);
});

it('startBackup emits a Create audit event with pending status payload', function () {
    $service = app(BackupMetadataService::class);
    $job     = $service->startBackup(isManual: true);

    $event = \App\Models\AuditEvent::where('auditable_id', $job->id)
        ->where('action', 'create')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->payload['status'])->toBe('pending')
        ->and($event->payload['is_manual'])->toBeTrue();
});

it('markRunning emits an Update audit event with pending_to_running transition', function () {
    $service = app(BackupMetadataService::class);
    $job     = $service->startBackup();
    $service->markRunning($job->id);

    $event = \App\Models\AuditEvent::where('auditable_id', $job->id)
        ->where('action', 'update')
        ->first();
    expect($event)->not->toBeNull()
        ->and($event->payload['status_transition'])->toBe('pending_to_running');
});

it('completeBackup emits an Update audit event with running_to_success transition and size', function () {
    $service = app(BackupMetadataService::class);
    $job     = $service->startBackup();
    $service->markRunning($job->id);
    $service->completeBackup($job->id, ['tables' => []], 42);

    $event = \App\Models\AuditEvent::where('auditable_id', $job->id)
        ->where('action', 'update')
        ->where('payload->status_transition', 'running_to_success')
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->payload['size_bytes'])->toBe(42);
});

it('failBackup emits an Update audit event with running_to_failed transition', function () {
    $service = app(BackupMetadataService::class);
    $job     = $service->startBackup();
    $service->markRunning($job->id);
    $service->failBackup($job->id, 'boom');

    $event = \App\Models\AuditEvent::where('auditable_id', $job->id)
        ->where('action', 'update')
        ->where('payload->status_transition', 'running_to_failed')
        ->first();
    expect($event)->not->toBeNull();
});

it('pruneExpired deletes the physical dump artifact referenced by the manifest', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    \Illuminate\Support\Facades\Storage::disk('local')->put('backups/old.sql.gz.enc', 'ciphertext');

    $expired = BackupJob::create([
        'started_at'           => now()->subDays(20),
        'status'               => 'success',
        'is_manual'            => false,
        'retention_expires_at' => now()->subDay(),
        'manifest'             => ['dump_file' => 'backups/old.sql.gz.enc'],
    ]);

    app(BackupMetadataService::class)->pruneExpired();

    expect(BackupJob::where('id', $expired->id)->exists())->toBeFalse();
    expect(\Illuminate\Support\Facades\Storage::disk('local')->exists('backups/old.sql.gz.enc'))->toBeFalse();
    expect(\App\Models\AuditEvent::where('auditable_id', $expired->id)
        ->where('action', 'delete')->exists())->toBeTrue();
});

it('pruneExpired tolerates manifest dump_file that does not exist on disk', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    BackupJob::create([
        'started_at'           => now()->subDays(20),
        'status'               => 'success',
        'is_manual'            => false,
        'retention_expires_at' => now()->subDay(),
        'manifest'             => ['dump_file' => 'backups/never-existed.sql.gz.enc'],
    ]);

    expect(app(BackupMetadataService::class)->pruneExpired())->toBe(1);
});
