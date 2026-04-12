<?php

use App\Application\Logging\StructuredLogger;
use App\Models\AuditEvent;
use App\Models\StructuredLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records an audit event when writing a structured log entry', function () {
    $logger = app(StructuredLogger::class);

    $logger->info('Test structured log write', [
        'token' => 'secret-token',
        'safe'  => 'value',
    ], 'application');

    $log = StructuredLog::first();

    expect($log)->not->toBeNull();
    expect($log->context['token'])->toBe('[REDACTED]');

    expect(AuditEvent::where('auditable_type', StructuredLog::class)
        ->where('auditable_id', $log->id)
        ->where('action', 'create')
        ->exists())->toBeTrue();
});

it('records delete audit events when pruning expired structured logs', function () {
    $logger = app(StructuredLogger::class);

    StructuredLog::create([
        'level'          => 'info',
        'message'        => 'Expired log',
        'context'        => [],
        'channel'        => 'application',
        'recorded_at'    => now()->subDays(100),
        'retained_until' => now()->subDay(),
    ]);

    $deleted = $logger->prune();

    expect($deleted)->toBe(1);
    expect(StructuredLog::count())->toBe(0);

    expect(AuditEvent::where('auditable_type', StructuredLog::class)
        ->where('action', 'delete')
        ->count())->toBe(1);
});
