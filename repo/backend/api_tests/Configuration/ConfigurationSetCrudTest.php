<?php

use App\Models\AuditEvent;
use App\Models\ConfigurationSet;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests covering index/show/update/destroy endpoints of ConfigurationSetController
 * — these routes were uncovered in the baseline and are the highest-ROI controller gap.
 */
describe('Configuration Set CRUD', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept      = Department::create(['name' => 'Pricing', 'code' => 'PRC']);
        $this->otherDept = Department::create(['name' => 'Ops',     'code' => 'OPS']);

        $this->admin = User::create([
            'username'      => 'cfg_admin',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Cfg Admin',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo(['manage configuration', 'view configuration']);

        $this->staff = User::create([
            'username'      => 'cfg_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Cfg Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');
        $this->staff->givePermissionTo('view configuration');
    });

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    it('index returns paginated list with admin seeing every set (all departments)', function () {
        ConfigurationSet::create(['name' => 'SysWide',  'department_id' => null,              'created_by' => $this->admin->id, 'is_active' => true]);
        ConfigurationSet::create(['name' => 'PrcSet',   'department_id' => $this->dept->id,   'created_by' => $this->admin->id, 'is_active' => true]);
        ConfigurationSet::create(['name' => 'OtherSet', 'department_id' => $this->otherDept->id, 'created_by' => $this->admin->id, 'is_active' => true]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/configuration/sets');

        $response->assertStatus(200)
                 ->assertJsonPath('meta.pagination.total', 3)
                 ->assertJsonStructure(['data', 'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']]]);
    });

    it('index scopes to own department + system-wide (department_id=null) for non-admin users', function () {
        ConfigurationSet::create(['name' => 'SysWide',  'department_id' => null,                 'created_by' => $this->admin->id, 'is_active' => true]);
        ConfigurationSet::create(['name' => 'OwnDept',  'department_id' => $this->dept->id,      'created_by' => $this->admin->id, 'is_active' => true]);
        ConfigurationSet::create(['name' => 'OtherDept','department_id' => $this->otherDept->id, 'created_by' => $this->admin->id, 'is_active' => true]);

        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/configuration/sets');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toContain('SysWide', 'OwnDept')
                      ->not->toContain('OtherDept');
    });

    it('index admin filter.department_id narrows results', function () {
        ConfigurationSet::create(['name' => 'A', 'department_id' => $this->dept->id,      'created_by' => $this->admin->id, 'is_active' => true]);
        ConfigurationSet::create(['name' => 'B', 'department_id' => $this->otherDept->id, 'created_by' => $this->admin->id, 'is_active' => true]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson("/api/v1/configuration/sets?filter[department_id]={$this->otherDept->id}");

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toBe(['B']);
    });

    it('index per_page is capped at 100', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/configuration/sets?per_page=500');
        $response->assertStatus(200)
                 ->assertJsonPath('meta.pagination.per_page', 100);
    });

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    it('show returns a configuration set with embedded versions', function () {
        $set = ConfigurationSet::create([
            'name'          => 'ShowMe',
            'department_id' => $this->dept->id,
            'created_by'    => $this->admin->id,
            'is_active'     => true,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson("/api/v1/configuration/sets/{$set->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $set->id)
                 ->assertJsonStructure(['data' => ['id', 'name', 'versions']]);
    });

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    it('update changes the name and records an Update audit event', function () {
        $set = ConfigurationSet::create([
            'name'          => 'OldName',
            'department_id' => $this->dept->id,
            'created_by'    => $this->admin->id,
            'is_active'     => true,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->putJson("/api/v1/configuration/sets/{$set->id}", [
            'name'        => 'NewName',
            'description' => 'Updated description',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.name', 'NewName');

        expect(AuditEvent::where('auditable_id', $set->id)
            ->where('action', 'update')->exists())->toBeTrue();
    });

    it('patch updates configuration set description and returns 200', function () {
        $set = ConfigurationSet::create([
            'name'          => 'Patchable Set',
            'department_id' => $this->dept->id,
            'created_by'    => $this->admin->id,
            'is_active'     => true,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->patchJson("/api/v1/configuration/sets/{$set->id}", [
            'description' => 'Patched config set description',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.description', 'Patched config set description')
                 ->assertJsonPath('data.name', 'Patchable Set');
    });

    // -------------------------------------------------------------------------
    // destroy
    // -------------------------------------------------------------------------

    it('destroy soft-deletes the set and records a Delete audit event', function () {
        $set = ConfigurationSet::create([
            'name'          => 'ToDelete',
            'department_id' => $this->dept->id,
            'created_by'    => $this->admin->id,
            'is_active'     => true,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->deleteJson("/api/v1/configuration/sets/{$set->id}", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(204);
        expect(ConfigurationSet::find($set->id))->toBeNull();
        expect(AuditEvent::where('auditable_id', $set->id)
            ->where('action', 'delete')->exists())->toBeTrue();
    });

    it('destroy returns 403 for staff user without manage configuration permission', function () {
        $set = ConfigurationSet::create([
            'name'          => 'Protected',
            'department_id' => $this->dept->id,
            'created_by'    => $this->admin->id,
            'is_active'     => true,
        ]);

        Sanctum::actingAs($this->staff);
        $response = $this->deleteJson("/api/v1/configuration/sets/{$set->id}", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403);
        expect(ConfigurationSet::find($set->id))->not->toBeNull();
    });
});
