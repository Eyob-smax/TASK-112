<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Infrastructure\Persistence\EloquentAuditEventRepository;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Unit tests for AuditEvent append-only immutability.
 *
 * The audit_events table is a critical invariant: records are created once
 * and must never be updated or deleted. The AuditEvent model enforces this
 * at the model level. The EloquentAuditEventRepository enforces idempotency
 * via the correlation_id unique constraint.
 *
 * These tests exercise real database interactions to confirm the invariants
 * hold at both the model and repository layers.
 */

uses(RefreshDatabase::class);

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function makeAuditEvent(string $correlationId = null): AuditEvent
{
    $event                = new AuditEvent();
    $event->correlation_id = $correlationId ?? (string) Str::uuid();
    $event->action        = AuditAction::Login;
    $event->actor_id      = null;
    $event->auditable_type = null;
    $event->auditable_id  = null;
    $event->before_hash   = null;
    $event->after_hash    = null;
    $event->payload       = ['source' => 'test'];
    $event->ip_address    = '127.0.0.1';
    $event->created_at    = now();

    return $event;
}

// -------------------------------------------------------------------------
// First insert — must succeed
// -------------------------------------------------------------------------

it('persists a new AuditEvent via save() on a fresh (non-existing) instance', function () {
    $event = makeAuditEvent();

    // save() on a fresh model (exists=false) is allowed
    $event->save();

    expect(AuditEvent::count())->toBe(1);
    expect(AuditEvent::first()->ip_address)->toBe('127.0.0.1');
});

it('sets created_at automatically when not provided', function () {
    $event = makeAuditEvent();
    // Don't set created_at explicitly
    $event->created_at = null;
    $event->save();

    $event->refresh();
    expect($event->created_at)->not->toBeNull();
});

// -------------------------------------------------------------------------
// Update prevention
// -------------------------------------------------------------------------

it('throws LogicException when save() is called on an already-persisted AuditEvent', function () {
    $event = makeAuditEvent();
    $event->save();

    // Attempt to update — model.exists is now true
    $event->ip_address = '10.0.0.1';

    expect(fn () => $event->save())
        ->toThrow(\LogicException::class, 'append-only');
});

it('does not modify the record even when LogicException is thrown on second save()', function () {
    $event = makeAuditEvent();
    $event->save();
    $originalIp = $event->ip_address;

    try {
        $event->ip_address = '10.0.0.1';
        $event->save();
    } catch (\LogicException) {
        // expected
    }

    $fresh = AuditEvent::find($event->id);
    expect($fresh->ip_address)->toBe($originalIp);
});

// -------------------------------------------------------------------------
// Delete prevention
// -------------------------------------------------------------------------

it('throws LogicException when delete() is called on an AuditEvent', function () {
    $event = makeAuditEvent();
    $event->save();

    expect(fn () => $event->delete())
        ->toThrow(\LogicException::class, 'append-only');
});

it('does not remove the record from the database when delete() throws', function () {
    $event = makeAuditEvent();
    $event->save();

    try {
        $event->delete();
    } catch (\LogicException) {
        // expected
    }

    expect(AuditEvent::count())->toBe(1);
});

it('throws LogicException when forceDelete() is called on an AuditEvent', function () {
    $event = makeAuditEvent();
    $event->save();

    expect(fn () => $event->forceDelete())
        ->toThrow(\LogicException::class, 'append-only');
});

// -------------------------------------------------------------------------
// No updated_at column
// -------------------------------------------------------------------------

it('has UPDATED_AT=null — no updated_at tracking on the model', function () {
    expect(AuditEvent::UPDATED_AT)->toBeNull();
});

it('does not have an updated_at column in the database schema', function () {
    $event = makeAuditEvent();
    $event->save();

    // The raw DB row must not have an updated_at column
    $raw = DB::table('audit_events')->where('id', $event->id)->first();

    expect(isset($raw->updated_at))->toBeFalse();
});

// -------------------------------------------------------------------------
// Correlation ID idempotency
// -------------------------------------------------------------------------

it('does not create a duplicate record when the same correlation_id is submitted twice', function () {
    $repo          = new EloquentAuditEventRepository();
    $correlationId = (string) Str::uuid();

    $repo->record(
        correlationId:  $correlationId,
        action:         AuditAction::Login,
        actorId:        null,
        auditableType:  null,
        auditableId:    null,
        beforeHash:     null,
        afterHash:      null,
        payload:        ['attempt' => 1],
        ipAddress:      '127.0.0.1',
    );

    // Replay with same correlation ID
    $repo->record(
        correlationId:  $correlationId,
        action:         AuditAction::Login,
        actorId:        null,
        auditableType:  null,
        auditableId:    null,
        beforeHash:     null,
        afterHash:      null,
        payload:        ['attempt' => 2],
        ipAddress:      '127.0.0.1',
    );

    // Only one record must exist
    expect(AuditEvent::count())->toBe(1);

    // The record reflects the FIRST call's payload, not the replay's
    $record = AuditEvent::first();
    expect($record->payload['attempt'])->toBe(1);
});

it('returns the existing record when correlation_id is replayed', function () {
    $repo          = new EloquentAuditEventRepository();
    $correlationId = (string) Str::uuid();

    $first = $repo->record(
        correlationId:  $correlationId,
        action:         AuditAction::Login,
        actorId:        null,
        auditableType:  null,
        auditableId:    null,
        beforeHash:     null,
        afterHash:      null,
        payload:        [],
        ipAddress:      '127.0.0.1',
    );

    $second = $repo->record(
        correlationId:  $correlationId,
        action:         AuditAction::Login,
        actorId:        null,
        auditableType:  null,
        auditableId:    null,
        beforeHash:     null,
        afterHash:      null,
        payload:        [],
        ipAddress:      '127.0.0.1',
    );

    expect($first->id)->toBe($second->id);
});

// -------------------------------------------------------------------------
// Database-level immutability (trigger enforcement — defense-in-depth)
// -------------------------------------------------------------------------

it('rejects raw query-builder UPDATE on audit_events at the database level', function () {
    $event = makeAuditEvent();
    $event->save();

    // DB trigger must raise SQLSTATE 45000 for any UPDATE, surfaced as QueryException
    expect(fn () => \Illuminate\Support\Facades\DB::table('audit_events')
        ->where('id', $event->id)
        ->update(['ip_address' => '10.0.0.1'])
    )->toThrow(\Illuminate\Database\QueryException::class);

    // Record must remain unchanged
    $fresh = AuditEvent::find($event->id);
    expect($fresh->ip_address)->toBe('127.0.0.1');
});

it('rejects raw query-builder DELETE on audit_events at the database level', function () {
    $event = makeAuditEvent();
    $event->save();

    // DB trigger must raise SQLSTATE 45000 for any DELETE, surfaced as QueryException
    expect(fn () => \Illuminate\Support\Facades\DB::table('audit_events')
        ->where('id', $event->id)
        ->delete()
    )->toThrow(\Illuminate\Database\QueryException::class);

    expect(AuditEvent::count())->toBe(1);
});

it('creates a new record for a different correlation_id', function () {
    $repo = new EloquentAuditEventRepository();

    $repo->record(
        correlationId:  (string) Str::uuid(),
        action:         AuditAction::Login,
        actorId:        null,
        auditableType:  null,
        auditableId:    null,
        beforeHash:     null,
        afterHash:      null,
        payload:        [],
        ipAddress:      '127.0.0.1',
    );

    $repo->record(
        correlationId:  (string) Str::uuid(),
        action:         AuditAction::Logout,
        actorId:        null,
        auditableType:  null,
        auditableId:    null,
        beforeHash:     null,
        afterHash:      null,
        payload:        [],
        ipAddress:      '127.0.0.1',
    );

    expect(AuditEvent::count())->toBe(2);
});
