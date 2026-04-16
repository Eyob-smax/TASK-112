<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API tests for Sanctum token revocation on logout.
 *
 * Verifies that after POST /api/v1/auth/logout:
 *  - The personal_access_token record is deleted from the database.
 *  - The revoked token can no longer authenticate subsequent requests.
 */
describe('Token Revocation on Logout', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $dept = Department::create(['name' => 'Finance', 'code' => 'FIN']);

        $this->user = User::create([
            'username'      => 'revoke_tester',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Revoke Tester',
            'department_id' => $dept->id,
            'is_active'     => true,
        ]);
        $this->user->assignRole('staff');
    });

    it('deletes the Sanctum token record after logout so it cannot be replayed', function () {
        // Obtain a real token via login
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'username' => 'revoke_tester',
            'password' => 'ValidPass1!',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('data.token');

        // Token row exists before logout
        expect(PersonalAccessToken::count())->toBeGreaterThan(0);

        // Logout using the issued bearer token
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/auth/logout', [], [
                'X-Idempotency-Key' => \Illuminate\Support\Str::uuid()->toString(),
            ])
            ->assertStatus(204);

        // Token row is permanently deleted — revocation is not just invalidation
        expect(PersonalAccessToken::count())->toBe(0);
    });

    it('returns 401 when the revoked token is used in a request after logout', function () {
        // Obtain a real token via login
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'username' => 'revoke_tester',
            'password' => 'ValidPass1!',
        ]);

        $loginResponse->assertStatus(200);
        $token = $loginResponse->json('data.token');

        // Confirm the token works before logout
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200);

        // Logout
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/auth/logout', [], [
                'X-Idempotency-Key' => \Illuminate\Support\Str::uuid()->toString(),
            ])
            ->assertStatus(204);

        // Same token must no longer authenticate
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    });
});
