<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for the Departments CRUD endpoints.
 *
 * GET /departments — requires "view departments" or "manage departments"
 * GET /departments/{id} — requires "view departments" or "manage departments"
 * POST /departments — requires "manage departments"
 * PUT /departments/{id} — requires "manage departments"
 * DELETE /departments/{id} — requires "manage departments" (soft-deactivate)
 */
describe('Departments API', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Head Office', 'code' => 'HO']);

        $this->admin = User::create([
            'username'      => 'dept_admin',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Dept Admin',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->admin->assignRole('admin');

        $this->staff = User::create([
            'username'      => 'dept_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Dept Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');

        $this->viewer = User::create([
            'username'      => 'dept_viewer',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Dept Viewer',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->viewer->assignRole('viewer');
    });

    // -------------------------------------------------------------------------
    // GET /departments
    // -------------------------------------------------------------------------

    it('returns 200 with active departments for users with department-view permission', function () {
        Sanctum::actingAs($this->staff);

        $response = $this->getJson('/api/v1/departments');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'data' => [
                         ['id', 'name', 'code', 'description', 'parent_id', 'is_active'],
                     ],
                 ]);

        expect(count($response->json('data')))->toBeGreaterThan(0);
    });

    it('returns 403 when an authenticated user lacks department-view permission', function () {
        Sanctum::actingAs($this->viewer);

        $response = $this->getJson('/api/v1/departments');

        $response->assertStatus(403);
    });

    it('returns 401 when an unauthenticated request tries to list departments', function () {
        $response = $this->getJson('/api/v1/departments');

        $response->assertStatus(401);
    });

    // -------------------------------------------------------------------------
    // GET /departments/{id}
    // -------------------------------------------------------------------------

    it('returns 200 with department detail for users with department-view permission', function () {
        Sanctum::actingAs($this->staff);

        $response = $this->getJson("/api/v1/departments/{$this->dept->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $this->dept->id)
                 ->assertJsonPath('data.code', 'HO');
    });

    it('returns 403 when viewing a department without required permission', function () {
        Sanctum::actingAs($this->viewer);

        $response = $this->getJson("/api/v1/departments/{$this->dept->id}");

        $response->assertStatus(403);
    });

    it('returns 404 when department does not exist', function () {
        Sanctum::actingAs($this->staff);

        $response = $this->getJson('/api/v1/departments/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
    });

    // -------------------------------------------------------------------------
    // POST /departments
    // -------------------------------------------------------------------------

    it('returns 201 when admin creates a department with valid payload', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/departments', [
            'name'        => 'Logistics',
            'code'        => 'LOG',
            'description' => 'Handles all logistics operations.',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.name', 'Logistics')
                 ->assertJsonPath('data.code', 'LOG')
                 ->assertJsonPath('data.is_active', true);
    });

    it('returns 403 when a non-admin user tries to create a department', function () {
        Sanctum::actingAs($this->staff);

        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Unauthorized Dept',
            'code' => 'UNAUTH',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403);
    });

    it('returns 422 when creating a department with a duplicate code', function () {
        Sanctum::actingAs($this->admin);

        // HO is already taken by $this->dept
        $response = $this->postJson('/api/v1/departments', [
            'name' => 'Another Head Office',
            'code' => 'HO',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(422);
    });

    // -------------------------------------------------------------------------
    // PUT /departments/{id}
    // -------------------------------------------------------------------------

    it('returns 200 when admin updates a department', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/v1/departments/{$this->dept->id}", [
            'name' => 'Head Office Updated',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.name', 'Head Office Updated');
    });

    it('returns 403 when a non-admin user tries to update a department', function () {
        Sanctum::actingAs($this->staff);

        $response = $this->putJson("/api/v1/departments/{$this->dept->id}", [
            'name' => 'Staff Attempt Update',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // DELETE /departments/{id}
    // -------------------------------------------------------------------------

    it('returns 204 when admin deactivates a department', function () {
        Sanctum::actingAs($this->admin);

        // Create a separate department to deactivate (so tests are not order-dependent)
        $toDeactivate = Department::create(['name' => 'To Deactivate', 'code' => 'DEACT']);

        $response = $this->deleteJson("/api/v1/departments/{$toDeactivate->id}", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(204);

        // Verify it is no longer returned in the active list
        $list = $this->getJson('/api/v1/departments')->json('data');
        $ids  = collect($list)->pluck('id')->all();
        expect($ids)->not->toContain($toDeactivate->id);
    });

    it('returns 403 when a non-admin user tries to deactivate a department', function () {
        Sanctum::actingAs($this->staff);

        $response = $this->deleteJson("/api/v1/departments/{$this->dept->id}", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(403);
    });
});
