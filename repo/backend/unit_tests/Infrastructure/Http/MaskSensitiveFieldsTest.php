<?php

use App\Http\Middleware\MaskSensitiveFields;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Unit tests for MaskSensitiveFields after-middleware.
 *
 * Verifies the recursive 'notes' masking logic applied to JSON responses
 * based on the authenticated user's role and permissions.
 */
describe('MaskSensitiveFields Middleware', function () {

    beforeEach(function () {
        $this->middleware = new MaskSensitiveFields();
    });

    afterEach(function () {
        Mockery::close();
    });

    // -------------------------------------------------------------------------
    // Unauthenticated — pass through
    // -------------------------------------------------------------------------

    it('passes through unauthenticated requests without masking notes', function () {
        $request = Request::create('/api/v1/documents', 'GET');
        // No user resolver set — $request->user() returns null

        $result = $this->middleware->handle($request, fn ($r) => response()->json(['notes' => 'secret']));

        expect(json_decode($result->getContent(), true)['notes'])->toBe('secret');
    });

    // -------------------------------------------------------------------------
    // Privileged roles — no masking
    // -------------------------------------------------------------------------

    it('does not mask notes for admin or auditor role', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with(['admin', 'auditor'])->andReturn(true);

        $request = Request::create('/api/v1/documents', 'GET');
        $request->setUserResolver(fn () => $user);

        $result = $this->middleware->handle($request, fn ($r) => response()->json(['notes' => 'confidential']));

        expect(json_decode($result->getContent(), true)['notes'])->toBe('confidential');
    });

    it('does not mask notes for manager role', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with(['admin', 'auditor'])->andReturn(false);
        $user->shouldReceive('hasPermissionTo')->with('manage configuration')->andReturn(false);
        $user->shouldReceive('hasRole')->with('manager')->andReturn(true);

        $request = Request::create('/api/v1/documents', 'GET');
        $request->setUserResolver(fn () => $user);

        $result = $this->middleware->handle($request, fn ($r) => response()->json(['notes' => 'manager can see']));

        expect(json_decode($result->getContent(), true)['notes'])->toBe('manager can see');
    });

    it('does not mask notes for a user with manage configuration permission', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with(['admin', 'auditor'])->andReturn(false);
        $user->shouldReceive('hasPermissionTo')->with('manage configuration')->andReturn(true);

        $request = Request::create('/api/v1/documents', 'GET');
        $request->setUserResolver(fn () => $user);

        $result = $this->middleware->handle($request, fn ($r) => response()->json(['notes' => 'config user sees this']));

        expect(json_decode($result->getContent(), true)['notes'])->toBe('config user sees this');
    });

    // -------------------------------------------------------------------------
    // Restricted roles — notes masked
    // -------------------------------------------------------------------------

    it('replaces notes with [REDACTED] for staff role', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with(['admin', 'auditor'])->andReturn(false);
        $user->shouldReceive('hasPermissionTo')->with('manage configuration')->andReturn(false);
        $user->shouldReceive('hasRole')->with('manager')->andReturn(false);

        $request = Request::create('/api/v1/documents', 'GET');
        $request->setUserResolver(fn () => $user);

        $result = $this->middleware->handle(
            $request,
            fn ($r) => response()->json(['title' => 'Doc A', 'notes' => 'hidden from staff']),
        );

        $body = json_decode($result->getContent(), true);
        expect($body['notes'])->toBe('[REDACTED]');
        expect($body['title'])->toBe('Doc A');
    });

    it('masks notes recursively inside nested arrays', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with(['admin', 'auditor'])->andReturn(false);
        $user->shouldReceive('hasPermissionTo')->with('manage configuration')->andReturn(false);
        $user->shouldReceive('hasRole')->with('manager')->andReturn(false);

        $request = Request::create('/api/v1/documents', 'GET');
        $request->setUserResolver(fn () => $user);

        $payload = [
            'data' => [
                ['id' => 1, 'notes' => 'first note'],
                ['id' => 2, 'notes' => 'second note'],
            ],
        ];

        $result = $this->middleware->handle($request, fn ($r) => response()->json($payload));

        $body = json_decode($result->getContent(), true);
        expect($body['data'][0]['notes'])->toBe('[REDACTED]');
        expect($body['data'][1]['notes'])->toBe('[REDACTED]');
        expect($body['data'][0]['id'])->toBe(1);
    });

    // -------------------------------------------------------------------------
    // Edge cases
    // -------------------------------------------------------------------------

    it('does not redact a notes value that is null', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with(['admin', 'auditor'])->andReturn(false);
        $user->shouldReceive('hasPermissionTo')->with('manage configuration')->andReturn(false);
        $user->shouldReceive('hasRole')->with('manager')->andReturn(false);

        $request = Request::create('/api/v1/documents', 'GET');
        $request->setUserResolver(fn () => $user);

        $result = $this->middleware->handle(
            $request,
            fn ($r) => response()->json(['notes' => null, 'title' => 'Null notes doc']),
        );

        $body = json_decode($result->getContent(), true);
        expect($body['notes'])->toBeNull();
    });

    it('passes through non-JSON responses without modification', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->with(['admin', 'auditor'])->andReturn(false);
        $user->shouldReceive('hasPermissionTo')->with('manage configuration')->andReturn(false);
        $user->shouldReceive('hasRole')->with('manager')->andReturn(false);

        $request = Request::create('/api/v1/documents', 'GET');
        $request->setUserResolver(fn () => $user);

        $textResponse = new \Illuminate\Http\Response(
            'notes: plaintext content',
            200,
            ['Content-Type' => 'text/plain'],
        );

        $result = $this->middleware->handle($request, fn ($r) => $textResponse);

        expect($result->getContent())->toBe('notes: plaintext content');
    });
});
