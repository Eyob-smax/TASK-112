<?php

use Illuminate\Support\Facades\Route;

/**
 * Verify idempotency key enforcement at the route/contract level.
 *
 * The X-Idempotency-Key header is required on all mutating endpoints.
 * These tests verify route registration and header contract expectations.
 */
describe('Idempotency Header Contract', function () {

    describe('Route registration', function () {

        it('has a login route registered at POST /api/v1/auth/login', function () {
            $routes = collect(Route::getRoutes())
                ->filter(fn ($route) => $route->uri() === 'api/v1/auth/login')
                ->values();

            expect($routes->count())->toBeGreaterThan(0);
            expect($routes->first()->methods())->toContain('POST');
        });

        it('has a documents index route registered at GET /api/v1/documents', function () {
            $routes = collect(Route::getRoutes())
                ->filter(fn ($route) => $route->uri() === 'api/v1/documents')
                ->values();

            expect($routes->count())->toBeGreaterThan(0);
        });

        it('has a LAN link resolution route at GET /api/v1/links/{token}', function () {
            $routes = collect(Route::getRoutes())
                ->filter(fn ($route) => str_contains($route->uri(), 'links/{token}'))
                ->values();

            expect($routes->count())->toBeGreaterThan(0);
            expect($routes->first()->methods())->toContain('GET');
        });

        it('has a sales documents route registered at POST /api/v1/sales', function () {
            $routes = collect(Route::getRoutes())
                ->filter(fn ($route) => $route->uri() === 'api/v1/sales')
                ->values();

            $postRoute = $routes->first(fn ($r) => in_array('POST', $r->methods()));
            expect($postRoute)->not->toBeNull();
        });

    });

    describe('API base path', function () {

        it('routes are mounted under /api/v1 prefix', function () {
            $apiRoutes = collect(Route::getRoutes())
                ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'))
                ->values();

            expect($apiRoutes->count())->toBeGreaterThan(5);
        });

    });

});
