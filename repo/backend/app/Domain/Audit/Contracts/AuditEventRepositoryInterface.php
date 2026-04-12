<?php

namespace App\Domain\Audit\Contracts;

use App\Domain\Audit\Enums\AuditAction;

/**
 * Contract for immutable audit event persistence.
 *
 * CRITICAL: The audit_events table is append-only.
 * No UPDATE or DELETE operations are permitted.
 *
 * Implementation: App\Infrastructure\Persistence\EloquentAuditEventRepository (Prompt 3+)
 */
interface AuditEventRepositoryInterface
{
    /**
     * Record an audit event.
     *
     * Idempotency: If a record with the given correlation_id already exists,
     * this method must return the existing record without creating a duplicate.
     *
     * @param string      $correlationId  Unique idempotency key for this audit entry
     * @param AuditAction $action         The type of action being recorded
     * @param array       $payload        Additional event data
     *
     * @return mixed The audit event record (existing or newly created)
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
    ): mixed;

    /**
     * Whether an audit event with the given correlation_id already exists.
     */
    public function correlationIdExists(string $correlationId): bool;
}
