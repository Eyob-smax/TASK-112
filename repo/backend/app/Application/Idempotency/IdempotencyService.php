<?php

namespace App\Application\Idempotency;

use App\Application\Idempotency\Contracts\IdempotencyServiceInterface;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Models\IdempotencyKey;
use Illuminate\Support\Str;

/**
 * Implements idempotency for mutating API endpoints.
 *
 * - Keys must be UUID v4 format (validated by isValidKey())
 * - Cached responses expire after 24 hours (from config meridian.idempotency.ttl_hours)
 * - Concurrent requests with the same key: insertOrIgnore ensures first-writer wins
 * - Keys are stored as SHA-256 hashes, not in plaintext
 * - Cache scope includes actor identity + HTTP method + request path
 * - Request payload digest is persisted to detect same-key/different-payload misuse
 */
class IdempotencyService implements IdempotencyServiceInterface
{
    /**
     * Hash a raw idempotency key for safe storage (SHA-256, 64 hex chars).
     */
    public function hashKey(string $idempotencyKey): string
    {
        return hash('sha256', $idempotencyKey);
    }

    /**
     * Hash actor scope for storage isolation (SHA-256, 64 hex chars).
     */
    public function hashActorScope(string $actorScope): string
    {
        return hash('sha256', $actorScope);
    }

    /**
     * Whether the given string is a valid UUID v4 (case-insensitive).
     */
    public function isValidKey(string $idempotencyKey): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $idempotencyKey
        );
    }

    /**
     * Look up a cached response for the given raw idempotency key, scoped to the
     * originating actor, HTTP method, and request path.
     *
     * @return array{status: int, body: array, request_hash: string}|null  Null if not cached or expired
     */
    public function getCachedResponse(
        string $idempotencyKey,
        string $httpMethod,
        string $requestPath,
        string $actorScope
    ): ?array {
        $record = IdempotencyKey::where('key_hash', $this->hashKey($idempotencyKey))
            ->where('actor_scope_hash', $this->hashActorScope($actorScope))
            ->where('http_method', strtoupper($httpMethod))
            ->where('request_path', $requestPath)
            ->where('expires_at', '>', now())
            ->first();

        if ($record === null) {
            return null;
        }

        return [
            'status'       => $record->response_status,
            'body'         => $record->response_body,
            'request_hash' => $record->request_hash,
        ];
    }

    /**
     * Store the response for an idempotency key.
     *
     * Uses insertOrIgnore so concurrent first-requests don't race — only the
     * first writer wins; subsequent identical writes are silently discarded.
     */
    public function storeResponse(
        string $idempotencyKey,
        int $statusCode,
        array $responseBody,
        string $httpMethod,
        string $requestPath,
        string $actorScope,
        string $requestHash
    ): void {
        $ttlHours = config('meridian.idempotency.ttl_hours', 24);

        $id        = (string) Str::uuid();
        $keyHash   = $this->hashKey($idempotencyKey);
        $actorHash = $this->hashActorScope($actorScope);

        $inserted = IdempotencyKey::insertOrIgnore([
            'id'               => $id,
            'key_hash'         => $keyHash,
            'actor_scope_hash' => $actorHash,
            'http_method'      => strtoupper($httpMethod),
            'request_path'     => $requestPath,
            'request_hash'     => $requestHash,
            'response_status'  => $statusCode,
            'response_body'    => json_encode($responseBody),
            'expires_at'       => now()->addHours($ttlHours),
            'created_at'       => now(),
        ]);

        // First-writer wins: audit only when this call actually persisted the row.
        if ($inserted === 1) {
            $record = IdempotencyKey::where('id', $id)->first();

            if ($record !== null) {
                $this->recordAudit(
                    action: AuditAction::Create,
                    auditableType: IdempotencyKey::class,
                    auditableId: $record->id,
                    afterHash: hash('sha256', json_encode($record->toArray())),
                );
            }
        }
    }

    private function recordAudit(
        AuditAction $action,
        string $auditableType,
        string $auditableId,
        ?string $beforeHash = null,
        ?string $afterHash = null,
    ): void {
        $audit = $this->resolveAuditRepository();

        if ($audit === null) {
            return;
        }

        $idempotencyKey = $this->resolveIdempotencyKey();
        $correlationId  = $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey . ':' . $auditableId . ':' . $action->value)
            : (string) Str::uuid();

        $audit->record(
            correlationId: $correlationId,
            action: $action,
            actorId: $this->resolveActorId(),
            auditableType: $auditableType,
            auditableId: $auditableId,
            beforeHash: $beforeHash,
            afterHash: $afterHash,
            payload: [
                'channel' => 'idempotency',
            ],
            ipAddress: $this->resolveIpAddress(),
        );
    }

    private function resolveAuditRepository(): ?AuditEventRepositoryInterface
    {
        try {
            return app()->bound(AuditEventRepositoryInterface::class)
                ? app(AuditEventRepositoryInterface::class)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveIdempotencyKey(): ?string
    {
        try {
            return request()->header('X-Idempotency-Key');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveActorId(): ?string
    {
        try {
            $id = auth()->id();

            return $id !== null ? (string) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveIpAddress(): string
    {
        try {
            return request()->ip() ?? '127.0.0.1';
        } catch (\Throwable) {
            return '127.0.0.1';
        }
    }
}
