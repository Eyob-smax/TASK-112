<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Models\AuditEvent;

class EloquentAuditEventRepository implements AuditEventRepositoryInterface
{
    /**
     * Record an audit event.
     *
     * Idempotency: If a record with the given correlation_id already exists,
     * the existing record is returned without creating a duplicate.
     *
     * CRITICAL: This repository NEVER calls update() or delete().
     * The AuditEvent model itself enforces this at the model level as well.
     */
    public function record(
        string $correlationId,
        AuditAction $action,
        ?string $actorId,
        ?string $auditableType,
        ?string $auditableId,
        ?string $beforeHash,
        ?string $afterHash,
        array $payload,
        string $ipAddress
    ): mixed {
        // Compliance guard: modification actions must always carry an afterHash.
        if ($action->isModification() && $afterHash === null) {
            throw new \InvalidArgumentException(
                "afterHash must not be null for modification action [{$action->value}]"
            );
        }

        // Idempotency check — return existing record without inserting a duplicate.
        if ($this->correlationIdExists($correlationId)) {
            return AuditEvent::where('correlation_id', $correlationId)->first();
        }

        $event = new AuditEvent();
        $event->correlation_id  = $correlationId;
        $event->action          = $action;
        $event->actor_id        = $actorId;
        $event->auditable_type  = $auditableType;
        $event->auditable_id    = $auditableId;
        $event->before_hash     = $beforeHash;
        $event->after_hash      = $afterHash;
        $event->payload         = $payload;
        $event->ip_address      = $ipAddress;
        $event->created_at      = now();

        // save() is overridden on AuditEvent to throw if $this->exists — safe here
        // because this is a new (non-persisted) model instance.
        $event->save();

        return $event;
    }

    /**
     * Whether an audit event with the given correlation_id already exists.
     */
    public function correlationIdExists(string $correlationId): bool
    {
        return AuditEvent::where('correlation_id', $correlationId)->exists();
    }
}
