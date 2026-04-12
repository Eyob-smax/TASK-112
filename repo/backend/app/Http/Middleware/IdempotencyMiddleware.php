<?php

namespace App\Http\Middleware;

use App\Application\Idempotency\Contracts\IdempotencyServiceInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces idempotency key requirements on all mutating HTTP methods.
 *
 * Behaviour:
 *   - GET, HEAD, OPTIONS requests pass through without any check
 *   - POST, PUT, PATCH, DELETE: X-Idempotency-Key header is required
 *   - Key must be a valid UUID v4
 *   - Cache scope is actor + method + path
 *   - Same key in same scope but with different payload returns 409 conflict
 *   - If a cached response exists for the key and payload matches, return it immediately with
 *     X-Idempotency-Replay: true header
 *   - After a successful (non-cached) response, persist the response body
 *     and status code for 24 hours
 */
class IdempotencyMiddleware
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Read-only methods do not require idempotency keys
        if (!in_array($request->method(), self::MUTATING_METHODS, strict: true)) {
            return $next($request);
        }

        $key = $request->header('X-Idempotency-Key');

        // Header is missing
        if ($key === null || $key === '') {
            return $this->errorResponse(
                code: 'idempotency_key_required',
                message: 'Mutating requests must include an X-Idempotency-Key header (UUID v4).',
                status: 422,
            );
        }

        // Header has an invalid format
        if (!$this->idempotency->isValidKey($key)) {
            return $this->errorResponse(
                code: 'idempotency_key_invalid',
                message: 'The X-Idempotency-Key header must be a valid UUID v4.',
                status: 422,
            );
        }

        $actorScope  = $this->resolveActorScope($request);
        $requestHash = $this->buildRequestFingerprint($request);

        // Return cached response if one exists — scoped to actor + method + path.
        $cached = $this->idempotency->getCachedResponse(
            $key,
            $request->method(),
            $request->path(),
            $actorScope
        );

        if ($cached !== null) {
            if (!hash_equals($cached['request_hash'], $requestHash)) {
                return $this->errorResponse(
                    code: 'idempotency_key_reused',
                    message: 'The X-Idempotency-Key has already been used for a different request payload in this endpoint scope.',
                    status: 409,
                    details: [
                        'method' => strtoupper($request->method()),
                        'path'   => $request->path(),
                    ],
                );
            }

            return response()
                ->json($cached['body'], $cached['status'])
                ->header('X-Idempotency-Replay', 'true');
        }

        // Process the request normally
        $response = $next($request);

        // Persist the response body for future replay (only for success/4xx; not 5xx)
        if ($response->getStatusCode() < 500) {
            $body = json_decode($response->getContent(), associative: true) ?? [];
            $this->idempotency->storeResponse(
                $key,
                $response->getStatusCode(),
                $body,
                $request->method(),
                $request->path(),
                $actorScope,
                $requestHash,
            );
        }

        return $response;
    }

    private function errorResponse(string $code, string $message, int $status, array $details = []): Response
    {
        return response()->json([
            'error' => [
                'code'    => $code,
                'message' => $message,
                'details' => $details === [] ? (object) [] : $details,
            ],
        ], $status);
    }

    private function resolveActorScope(Request $request): string
    {
        $actorId = $request->user()?->getAuthIdentifier();

        return $actorId !== null ? (string) $actorId : 'guest';
    }

    private function buildRequestFingerprint(Request $request): string
    {
        $normalized = [
            'method' => strtoupper($request->method()),
            'path'   => $request->path(),
            'query'  => $this->normalizeValue($request->query()),
            'body'   => $this->normalizeValue($request->request->all()),
            'files'  => $this->normalizeFiles($request->allFiles()),
        ];

        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded)) {
            $encoded = serialize($normalized);
        }

        return hash('sha256', $encoded);
    }

    private function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $key => $value) {
            $normalized[$key] = $this->normalizeFileNode($value);
        }

        return $this->normalizeValue($normalized);
    }

    private function normalizeFileNode(mixed $node): mixed
    {
        if ($node instanceof UploadedFile) {
            $realPath = $node->getRealPath();
            $sha256   = is_string($realPath) && is_file($realPath)
                ? hash_file('sha256', $realPath)
                : null;

            return [
                'name'   => $node->getClientOriginalName(),
                'size'   => (int) $node->getSize(),
                'mime'   => $node->getClientMimeType(),
                'sha256' => $sha256,
            ];
        }

        if (!is_array($node)) {
            return $node;
        }

        $normalized = [];
        foreach ($node as $key => $value) {
            $normalized[$key] = $this->normalizeFileNode($value);
        }

        return $this->normalizeValue($normalized);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($this->isAssociativeArray($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }

        return $value;
    }

    private function isAssociativeArray(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
