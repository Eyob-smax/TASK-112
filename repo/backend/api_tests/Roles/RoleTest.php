<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for the Roles listing endpoint.
 *
 * GET /api/v1/roles — admin only.
 */
describe('Roles API', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Operations', 'code' => 'OPS']);

        $this->admin = User::create([
            'username'      => 'roles_admin',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Roles Admin',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->admin->assignRole('admin');

        $this->staff = User::create([
            'username'      => 'roles_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Roles Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');
    });

    // -------------------------------------------------------------------------
    // GET /roles — listing
    // -------------------------------------------------------------------------

    it('returns 200 with all roles for admin user', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         ['id', 'name', 'type', 'description'],
                     ],
                 ]);

        // The seeder must have created at least the standard roles
        expect(count($response->json('data')))->toBeGreaterThan(0);
    });

    it('returns role names as strings in the data array', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toContain('admin')
                      ->toContain('staff');
    });

    it('returns 403 when a non-admin staff user tries to list roles', function () {
        Sanctum::actingAs($this->staff);

        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(403);
    });

    it('returns 401 when an unauthenticated request tries to list roles', function () {
        $response = $this->getJson('/api/v1/roles');

        $response->assertStatus(401);
    });
});
