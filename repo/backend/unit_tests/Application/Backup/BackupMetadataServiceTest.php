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
