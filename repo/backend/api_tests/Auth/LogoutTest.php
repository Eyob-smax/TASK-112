<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for POST /api/v1/auth/logout
 */
describe('POST /api/v1/auth/logout', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $dept = Department::create(['name' => 'HR', 'code' => 'HR']);

        $this->user = User::create([
            'username'      => 'logoutuser',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Logout User',
            'department_id' => $dept->id,
            'is_active'     => true,
        ]);
    });

    it('returns 204 on authenticated logout', function () {
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        $response = $this->postJson('/api/v1/auth/logout', [], [
            'X-Idempotency-Key' => \Illuminate\Support\Str::uuid()->toString(),
        ]);

        $response->assertStatus(204);
    });

    it('returns 401 when no token is provided', function () {
        $response = $this->postJson('/api/v1/auth/logout', [], [
            'X-Idempotency-Key' => \Illuminate\Support\Str::uuid()->toString(),
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => ['code' => 'unauthenticated'],
            ]);
    });

});
