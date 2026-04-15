<?php

use App\Application\Metrics\MetricsRetentionService;
use App\Jobs\RecordQueueDepthJob;
use App\Models\MetricsSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| RecordQueueDepthJob — Integration Tests
|--------------------------------------------------------------------------
| Small job but uncovered — snapshots queue depth as a metrics record.
*/

uses(RefreshDatabase::class);

it('RecordQueueDepthJob records current jobs-table count as a queue_depth metric with driver=database', function () {
    DB::table('jobs')->insert([
        'queue'       => 'default',
        'payload'     => '{}',
        'attempts'    => 0,
        'reserved_at' => null,
        'available_at'=> now()->timestamp,
        'created_at'  => now()->timestamp,
    ]);
    DB::table('jobs')->insert([
        'queue'       => 'default',
        'payload'     => '{}',
        'attempts'    => 0,
        'reserved_at' => null,
        'available_at'=> now()->timestamp,
        'created_at'  => now()->timestamp,
    ]);

    (new RecordQueueDepthJob())->handle(app(MetricsRetentionService::class));

    $snap = MetricsSnapshot::where('metric_type', 'queue_depth')->first();
    expect($snap)->not->toBeNull()
        ->and($snap->value)->toBe(2.0)
        ->and($snap->labels)->toBe(['driver' => 'database']);
});

it('RecordQueueDepthJob records zero depth when jobs table is empty', function () {
    (new RecordQueueDepthJob())->handle(app(MetricsRetentionService::class));

    $snap = MetricsSnapshot::where('metric_type', 'queue_depth')->first();
    expect($snap)->not->toBeNull()
        ->and($snap->value)->toBe(0.0);
});
