<?php

namespace App\Application\Idempotency\Contracts;

/**
 * Contract for the write-API idempotency service.
 *
 * Behavior (from original prompt and api-spec.md):
 *   - X-Idempotency-Key header on mutating requests
 *   - Same key within 24h TTL returns the cached response
 *   - Different key produces a new response
 *   - Concurrent requests with the same key: first wins, subsequent wait for cached response
 *
 * Implementation: App\Application\Idempotency\IdempotencyService (Prompt 3+)
 */
interface IdempotencyServiceInterface
{
    /**
    * Check whether a cached response exists for this idempotency key, scoped to the
    * originating actor, HTTP method, and request path so the same raw key cannot
    * replay across identities or endpoints.
     *
     * @param string $idempotencyKey  The raw idempotency key from X-Idempotency-Key header
     * @param string $httpMethod      HTTP method of the current request (POST, PUT, etc.)
     * @param string $requestPath     Request path of the current request
    * @param string $actorScope      Stable actor scope (typically authenticated user UUID)
    * @return array{status: int, body: array, request_hash: string}|null  Null if not found or expired
     */
    public function getCachedResponse(string $idempotencyKey, string $httpMethod, string $requestPath, string $actorScope): ?array;

    /**
     * Store the response for an idempotency key.
     *
     * @param string $idempotencyKey  The raw idempotency key
     * @param int    $statusCode      HTTP status code of the response
     * @param array  $responseBody    Response body to cache
     * @param string $httpMethod      HTTP method of the original request (POST, PUT, etc.)
     * @param string $requestPath     Request path for conflict detection (without leading slash)
     * @param string $actorScope      Stable actor scope (typically authenticated user UUID)
     * @param string $requestHash     SHA-256 hash of canonicalized request payload
     */
    public function storeResponse(
        string $idempotencyKey,
        int $statusCode,
        array $responseBody,
        string $httpMethod,
        string $requestPath,
        string $actorScope,
        string $requestHash
    ): void;

    /**
     * Hash an idempotency key for storage (SHA-256 of the raw key string).
     */
    public function hashKey(string $idempotencyKey): string;

    /**
     * Whether a given idempotency key is a valid UUID format.
     */
    public function isValidKey(string $idempotencyKey): bool;
}
