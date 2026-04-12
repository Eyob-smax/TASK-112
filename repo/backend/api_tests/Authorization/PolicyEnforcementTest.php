<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for authorization (role-based access control enforcement).
 *
 * Verifies that policies correctly gate access to protected endpoints.
 */
describe('Authorization — Policy Enforcement', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $dept = Department::create(['name' => 'Sales Dept', 'code' => 'SLD']);

        $this->staffUser = User::create([
            'username'      => 'staff_tester',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Staff Tester',
            'department_id' => $dept->id,
            'is_active'     => true,
        ]);
        $this->staffUser->assignRole('staff');

        $this->viewerUser = User::create([
            'username'      => 'viewer_tester',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Viewer Tester',
            'department_id' => $dept->id,
            'is_active'     => true,
        ]);
        $this->viewerUser->assignRole('viewer');
    });

    it('returns 403 when a viewer tries to access audit events', function () {
        Sanctum::actingAs($this->viewerUser, ['*'], 'sanctum');

        $response = $this->getJson('/api/v1/audit/events');

        $response->assertStatus(403)
            ->assertJson([
                'error' => ['code' => 'forbidden'],
            ]);
    });

    it('returns 403 when a staff user tries to access audit events', function () {
        Sanctum::actingAs($this->staffUser, ['*'], 'sanctum');

        $response = $this->getJson('/api/v1/audit/events');

        $response->assertStatus(403)
            ->assertJson([
                'error' => ['code' => 'forbidden'],
            ]);
    });

    it('returns 401 when unauthenticated request hits a protected route', function () {
        // No Sanctum token
        $response = $this->getJson('/api/v1/audit/events');

        $response->assertStatus(401)
            ->assertJson([
                'error' => ['code' => 'unauthenticated'],
            ]);
    });

    it('GET requests bypass idempotency key requirement', function () {
        Sanctum::actingAs($this->viewerUser, ['*'], 'sanctum');

        // GET /auth/me does not need X-Idempotency-Key
        $response = $this->getJson('/api/v1/auth/me');

        // Should get a real response (200), not a 422 from idempotency check
        $response->assertStatus(200);
    });

});
