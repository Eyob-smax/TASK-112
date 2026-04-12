<?php

use Illuminate\Testing\Fluent\AssertableJson;

/**
 * Verify that the API error envelope format is consistent across all error conditions.
 *
 * Expected error format:
 * {
 *   "error": {
 *     "code": "string",
 *     "message": "string",
 *     "details": {}
 *   }
 * }
 */
describe('Error Envelope Contract', function () {

    describe('404 Not Found', function () {

        it('returns standard error envelope for nonexistent API route', function () {
            $response = $this->getJson('/api/v1/nonexistent-route-xyz');

            $response->assertStatus(404)
                ->assertJson(fn (AssertableJson $json) =>
                    $json->has('error')
                        ->where('error.code', 'not_found')
                        ->has('error.message')
                        ->has('error.details')
                        ->etc()
                );
        });

        it('does not expose a stack trace in the error response', function () {
            $response = $this->getJson('/api/v1/nonexistent-route-xyz');

            $content = $response->getContent();
            expect($content)->not->toContain('trace')
                ->and($content)->not->toContain('file')
                ->and($content)->not->toContain('line');
        });

    });

    describe('401 Unauthenticated', function () {

        it('returns standard error envelope when no bearer token is provided', function () {
            $response = $this->getJson('/api/v1/documents');

            $response->assertStatus(401)
                ->assertJson(fn (AssertableJson $json) =>
                    $json->has('error')
                        ->where('error.code', 'unauthenticated')
                        ->has('error.message')
                        ->has('error.details')
                        ->etc()
                );
        });

        it('returns 401 for an invalid bearer token', function () {
            $response = $this->withHeader('Authorization', 'Bearer invalid-token-here')
                ->getJson('/api/v1/documents');

            $response->assertStatus(401);
        });

    });

    describe('405 Method Not Allowed', function () {

        it('returns standard error envelope for a disallowed HTTP method', function () {
            // POST /api/v1/auth/login only accepts POST — DELETE is not allowed
            $response = $this->deleteJson('/api/v1/auth/login');

            $response->assertStatus(405)
                ->assertJsonStructure([
                    'error' => [
                        'code',
                        'message',
                        'details',
                    ],
                ]);
        });

    });

    describe('Error response structure', function () {

        it('error envelope always has exactly the required keys', function () {
            $response = $this->getJson('/api/v1/nonexistent-route-xyz');

            $response->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                    'details',
                ],
            ]);
        });

        it('error envelope does not contain a data key', function () {
            $response = $this->getJson('/api/v1/nonexistent-route-xyz');
            $response->assertJsonMissing(['data']);
        });

    });

});
