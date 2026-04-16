<?php

use App\Application\Idempotency\Contracts\IdempotencyServiceInterface;
use App\Http\Middleware\IdempotencyMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Unit tests for IdempotencyMiddleware.
 *
 * Covers: read-method pass-through, missing header, invalid key format,
 * cached replay with matching hash, 409 on hash mismatch, new-request storage,
 * and no-store guarantee for 5xx responses.
 */
describe('IdempotencyMiddleware', function () {

    beforeEach(function () {
        $this->idempotency = Mockery::mock(IdempotencyServiceInterface::class);
        $this->middleware  = new IdempotencyMiddleware($this->idempotency);
    });

    afterEach(function () {
        Mockery::close();
    });

    // -------------------------------------------------------------------------
    // Read-only methods — pass through without any idempotency check
    // -------------------------------------------------------------------------

    it('passes GET requests through without checking idempotency key', function () {
        $request  = Request::create('/api/v1/documents', 'GET');
        $response = $this->middleware->handle($request, fn ($r) => response()->json(['ok' => true], 200));

        expect($response->getStatusCode())->toBe(200);
    });

    it('passes HEAD requests through without checking idempotency key', function () {
        $request  = Request::create('/api/v1/documents', 'HEAD');
        $response = $this->middleware->handle($request, fn ($r) => response()->json(['ok' => true], 200));

        expect($response->getStatusCode())->toBe(200);
    });

    it('passes OPTIONS requests through without checking idempotency key', function () {
        $request  = Request::create('/api/v1/documents', 'OPTIONS');
        $response = $this->middleware->handle($request, fn ($r) => response()->json(['ok' => true], 200));

        expect($response->getStatusCode())->toBe(200);
    });

    // -------------------------------------------------------------------------
    // Missing X-Idempotency-Key header
    // -------------------------------------------------------------------------

    it('returns 422 with idempotency_key_required when header is absent on POST', function () {
        $request  = Request::create('/api/v1/documents', 'POST');
        $response = $this->middleware->handle($request, fn ($r) => response()->json([], 201));

        expect($response->getStatusCode())->toBe(422);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('idempotency_key_required');
    });

    it('returns 422 with idempotency_key_required when header is absent on DELETE', function () {
        $request  = Request::create('/api/v1/documents/abc', 'DELETE');
        $response = $this->middleware->handle($request, fn ($r) => response()->json([], 204));

        expect($response->getStatusCode())->toBe(422);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('idempotency_key_required');
    });

    // -------------------------------------------------------------------------
    // Invalid key format
    // -------------------------------------------------------------------------

    it('returns 422 with idempotency_key_invalid when key is not a valid UUID v4', function () {
        $this->idempotency->shouldReceive('isValidKey')->with('not-a-uuid')->andReturn(false);

        $request = Request::create(
            '/api/v1/documents',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_IDEMPOTENCY_KEY' => 'not-a-uuid'],
        );

        $response = $this->middleware->handle($request, fn ($r) => response()->json([], 201));

        expect($response->getStatusCode())->toBe(422);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('idempotency_key_invalid');
    });

    // -------------------------------------------------------------------------
    // Cached response — matching hash → replay
    // -------------------------------------------------------------------------

    it('returns cached response with X-Idempotency-Replay: true when hash matches', function () {
        $key = Str::uuid()->toString();

        // Reproduce the hash the middleware will compute for an empty POST to /api/v1/documents
        $expectedHash = hash('sha256', json_encode([
            'method' => 'POST',
            'path'   => 'api/v1/documents',
            'query'  => [],
            'body'   => [],
            'files'  => [],
        ], JSON_UNESCAPED_SLASHES));

        $this->idempotency->shouldReceive('isValidKey')->with($key)->andReturn(true);
        $this->idempotency->shouldReceive('getCachedResponse')->andReturn([
            'status'       => 201,
            'body'         => ['id' => 'cached-item'],
            'request_hash' => $expectedHash,
        ]);

        $request = Request::create(
            '/api/v1/documents',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_IDEMPOTENCY_KEY' => $key],
        );

        // next should not be called — the cached branch returns early
        $response = $this->middleware->handle($request, fn ($r) => response()->json([], 500));

        expect($response->getStatusCode())->toBe(201);
        expect($response->headers->get('X-Idempotency-Replay'))->toBe('true');
        expect(json_decode($response->getContent(), true)['id'])->toBe('cached-item');
    });

    // -------------------------------------------------------------------------
    // Cached response — hash mismatch → 409 conflict
    // -------------------------------------------------------------------------

    it('returns 409 with idempotency_key_reused when cached hash does not match the request body', function () {
        $key = Str::uuid()->toString();

        $this->idempotency->shouldReceive('isValidKey')->with($key)->andReturn(true);
        $this->idempotency->shouldReceive('getCachedResponse')->andReturn([
            'status'       => 201,
            'body'         => ['id' => 'original'],
            'request_hash' => 'completely-different-hash',
        ]);

        $request = Request::create(
            '/api/v1/documents',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_IDEMPOTENCY_KEY' => $key],
        );

        $response = $this->middleware->handle($request, fn ($r) => response()->json([], 201));

        expect($response->getStatusCode())->toBe(409);
        $body = json_decode($response->getContent(), true);
        expect($body['error']['code'])->toBe('idempotency_key_reused');
    });

    // -------------------------------------------------------------------------
    // New request — forward to handler and persist response
    // -------------------------------------------------------------------------

    it('forwards the request and stores a successful response in the cache', function () {
        $key = Str::uuid()->toString();

        $this->idempotency->shouldReceive('isValidKey')->andReturn(true);
        $this->idempotency->shouldReceive('getCachedResponse')->andReturn(null);
        $this->idempotency->shouldReceive('storeResponse')->once();

        $request = Request::create(
            '/api/v1/documents',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_IDEMPOTENCY_KEY' => $key],
        );

        $response = $this->middleware->handle($request, fn ($r) => response()->json(['id' => 'new'], 201));

        expect($response->getStatusCode())->toBe(201);
    });

    // -------------------------------------------------------------------------
    // 5xx responses must NOT be cached
    // -------------------------------------------------------------------------

    it('does not call storeResponse for a 500 server error', function () {
        $key = Str::uuid()->toString();

        $this->idempotency->shouldReceive('isValidKey')->andReturn(true);
        $this->idempotency->shouldReceive('getCachedResponse')->andReturn(null);
        $this->idempotency->shouldNotReceive('storeResponse');

        $request = Request::create(
            '/api/v1/documents',
            'POST',
            [],
            [],
            [],
            ['HTTP_X_IDEMPOTENCY_KEY' => $key],
        );

        $response = $this->middleware->handle(
            $request,
            fn ($r) => response()->json(['error' => 'internal server error'], 500),
        );

        expect($response->getStatusCode())->toBe(500);
    });
});
