<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for GET /api/v1/auth/me
 */
describe('GET /api/v1/auth/me', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $dept = Department::create(['name' => 'Finance', 'code' => 'FIN']);

        $this->user = User::create([
            'username'      => 'meuser',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Me User',
            'department_id' => $dept->id,
            'is_active'     => true,
        ]);

        $this->user->assignRole('staff');
    });

    it('returns 200 with authenticated user data', function () {
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'username',
                    'display_name',
                    'department_id',
                    'roles',
                    'permissions',
                ],
            ]);

        expect($response->json('data.id'))->toBe($this->user->id);
        expect($response->json('data.username'))->toBe('meuser');
    });

    it('includes assigned roles in the response', function () {
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        $response = $this->getJson('/api/v1/auth/me');

        $roles = $response->json('data.roles');
        expect($roles)->toContain('staff');
    });

    it('returns 401 when no token is provided', function () {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401)
            ->assertJson([
                'error' => ['code' => 'unauthenticated'],
            ]);
    });

});
