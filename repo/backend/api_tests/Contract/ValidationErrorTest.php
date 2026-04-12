<?php

use Illuminate\Testing\Fluent\AssertableJson;

/**
 * Verify that validation error responses follow the standard envelope format.
 *
 * Expected validation error format:
 * {
 *   "error": {
 *     "code": "validation_error",
 *     "message": "The given data was invalid.",
 *     "details": {
 *       "field_name": ["Error message 1"]
 *     }
 *   }
 * }
 */
describe('Validation Error Contract', function () {

    describe('POST /api/v1/auth/login', function () {

        it('returns 422 with validation error envelope when username is missing', function () {
            $response = $this->postJson('/api/v1/auth/login', [
                'password' => 'SomePassword123',
            ]);

            $response->assertStatus(422)
                ->assertJson(fn (AssertableJson $json) =>
                    $json->where('error.code', 'validation_error')
                        ->has('error.message')
                        ->has('error.details.username')
                        ->etc()
                );
        });

        it('returns 422 with validation error envelope when password is missing', function () {
            $response = $this->postJson('/api/v1/auth/login', [
                'username' => 'someuser',
            ]);

            $response->assertStatus(422)
                ->assertJson(fn (AssertableJson $json) =>
                    $json->where('error.code', 'validation_error')
                        ->has('error.message')
                        ->has('error.details.password')
                        ->etc()
                );
        });

        it('returns 422 when both username and password are missing', function () {
            $response = $this->postJson('/api/v1/auth/login', []);

            $response->assertStatus(422);
            $data = $response->json();
            expect($data['error']['code'])->toBe('validation_error');
            expect($data['error']['details'])->toHaveKey('username');
            expect($data['error']['details'])->toHaveKey('password');
        });

        it('does not return a 200 status for empty login payload', function () {
            $response = $this->postJson('/api/v1/auth/login', []);
            $response->assertStatus(422);
        });

    });

    describe('Validation error details format', function () {

        it('validation details contain arrays of error strings per field', function () {
            $response = $this->postJson('/api/v1/auth/login', []);
            $details = $response->json('error.details');

            expect($details)->toBeArray();
            foreach ($details as $fieldErrors) {
                expect($fieldErrors)->toBeArray();
                foreach ($fieldErrors as $msg) {
                    expect($msg)->toBeString();
                }
            }
        });

    });

});
