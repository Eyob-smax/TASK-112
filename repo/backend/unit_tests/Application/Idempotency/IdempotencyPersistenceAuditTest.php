<?php

use App\Application\Idempotency\IdempotencyService;
use App\Models\AuditEvent;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('writes an idempotency cache record and corresponding audit event on first insert', function () {
    $service = app(IdempotencyService::class);

    $service->storeResponse(
        idempotencyKey: '550e8400-e29b-41d4-a716-446655440000',
        statusCode: 200,
        responseBody: ['ok' => true],
        httpMethod: 'POST',
        requestPath: 'api/v1/sales',
        actorScope: 'actor-1',
        requestHash: hash('sha256', '{"ok":true}')
    );

    expect(IdempotencyKey::count())->toBe(1);

    $row = IdempotencyKey::first();

    expect(AuditEvent::where('auditable_type', IdempotencyKey::class)
        ->where('auditable_id', $row->id)
        ->where('action', 'create')
        ->exists())->toBeTrue();
});

it('does not create duplicate idempotency rows or duplicate create-audit events on replay', function () {
    $service = app(IdempotencyService::class);

    $key = '550e8400-e29b-41d4-a716-446655440001';

    $service->storeResponse(
        $key,
        200,
        ['first' => true],
        'POST',
        'api/v1/sales',
        'actor-1',
        hash('sha256', '{"first":true}')
    );
    $service->storeResponse(
        $key,
        200,
        ['second' => true],
        'POST',
        'api/v1/sales',
        'actor-1',
        hash('sha256', '{"second":true}')
    );

    expect(IdempotencyKey::count())->toBe(1);

    expect(AuditEvent::where('auditable_type', IdempotencyKey::class)
        ->where('action', 'create')
        ->count())->toBe(1);
});

it('stores separate rows for the same key when actor scope differs', function () {
    $service = app(IdempotencyService::class);

    $key = '550e8400-e29b-41d4-a716-446655440002';

    $service->storeResponse(
        $key,
        200,
        ['ok' => true],
        'POST',
        'api/v1/sales',
        'actor-alpha',
        hash('sha256', '{"op":"same"}')
    );
    $service->storeResponse(
        $key,
        200,
        ['ok' => true],
        'POST',
        'api/v1/sales',
        'actor-beta',
        hash('sha256', '{"op":"same"}')
    );

    expect(IdempotencyKey::count())->toBe(2);

    expect(AuditEvent::where('auditable_type', IdempotencyKey::class)
        ->where('action', 'create')
        ->count())->toBe(2);
});
