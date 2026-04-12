<?php

use App\Application\Metrics\MetricsRetentionService;
use App\Models\AuditEvent;
use App\Models\MetricsSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| MetricsRetentionService — Unit Tests
|--------------------------------------------------------------------------
| Covers: snapshot recording, 90-day retention pruning, label storage.
*/

uses(RefreshDatabase::class);

it('records a metrics snapshot with the correct metric_type and value', function () {
    $service = app(MetricsRetentionService::class);

    $snapshot = $service->record('request_timing', 245.7, ['route' => '/api/v1/sales']);

    expect($snapshot->metric_type)->toBe('request_timing')
        ->and($snapshot->value)->toBe(245.7)
        ->and($snapshot->labels['route'])->toBe('/api/v1/sales')
        ->and($snapshot->recorded_at)->not->toBeNull()
        ->and($snapshot->retained_until)->not->toBeNull();

    expect(AuditEvent::where('auditable_type', MetricsSnapshot::class)
        ->where('auditable_id', $snapshot->id)
        ->where('action', 'create')
        ->exists())->toBeTrue();
});

it('sets retained_until to now() + configured metrics retention days', function () {
    $service = app(MetricsRetentionService::class);

    $snapshot = $service->record('queue_depth', 4.0);

    $retentionDays = (int) config('meridian.retention.metrics_days', 90);
    $expected      = now()->addDays($retentionDays)->startOfMinute();
    $actual        = $snapshot->retained_until->startOfMinute();

    expect($actual->equalTo($expected))->toBeTrue();
});

it('records a failed_approvals metric snapshot', function () {
    $service  = app(MetricsRetentionService::class);
    $snapshot = $service->record('failed_approvals', 3.0, ['department_id' => 'abc-123']);

    expect($snapshot->metric_type)->toBe('failed_approvals')
        ->and($snapshot->value)->toBe(3.0)
        ->and($snapshot->labels['department_id'])->toBe('abc-123');
});

it('records a snapshot with no labels when labels array is empty', function () {
    $service  = app(MetricsRetentionService::class);
    $snapshot = $service->record('queue_depth', 0.0);

    expect($snapshot->labels)->toBeArray()
        ->and($snapshot->labels)->toBeEmpty();
});

it('prunes metrics snapshots whose retention window has expired', function () {
    $service = app(MetricsRetentionService::class);

    // Expired snapshot
    MetricsSnapshot::create([
        'metric_type'    => 'request_timing',
        'value'          => 100.0,
        'labels'         => [],
        'recorded_at'    => now()->subDays(100),
        'retained_until' => now()->subDays(10),  // expired
    ]);

    // Still-valid snapshot
    MetricsSnapshot::create([
        'metric_type'    => 'queue_depth',
        'value'          => 2.0,
        'labels'         => [],
        'recorded_at'    => now()->subDays(30),
        'retained_until' => now()->addDays(60),  // within retention
    ]);

    $deleted = $service->pruneExpired();

    expect($deleted)->toBe(1)
        ->and(MetricsSnapshot::count())->toBe(1)
        ->and(MetricsSnapshot::first()->metric_type)->toBe('queue_depth');

    expect(AuditEvent::where('auditable_type', MetricsSnapshot::class)
        ->where('action', 'delete')
        ->count())->toBe(1);
});

it('prunes all three metric types correctly', function () {
    $service = app(MetricsRetentionService::class);

    foreach (['request_timing', 'queue_depth', 'failed_approvals'] as $type) {
        MetricsSnapshot::create([
            'metric_type'    => $type,
            'value'          => 1.0,
            'labels'         => [],
            'recorded_at'    => now()->subDays(100),
            'retained_until' => now()->subDays(1),
        ]);
    }

    $deleted = $service->pruneExpired();

    expect($deleted)->toBe(3)
        ->and(MetricsSnapshot::count())->toBe(0);
});

it('returns zero from pruneExpired when no snapshots have expired', function () {
    $service = app(MetricsRetentionService::class);

    MetricsSnapshot::create([
        'metric_type'    => 'request_timing',
        'value'          => 50.0,
        'labels'         => [],
        'recorded_at'    => now(),
        'retained_until' => now()->addDays(90),
    ]);

    expect($service->pruneExpired())->toBe(0);
});
