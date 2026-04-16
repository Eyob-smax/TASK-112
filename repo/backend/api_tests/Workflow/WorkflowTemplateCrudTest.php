<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests covering index/update/destroy endpoints of WorkflowTemplateController,
 * including department-scoped listings and filter combinations.
 */
describe('Workflow Template CRUD', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept      = Department::create(['name' => 'HR',      'code' => 'HR']);
        $this->otherDept = Department::create(['name' => 'Finance', 'code' => 'FIN']);

        $this->admin = User::create([
            'username'      => 'wftpl_admin',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Tpl Admin',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->admin->assignRole('admin');
        $this->admin->givePermissionTo(['manage workflow templates', 'view workflow']);

        $this->staff = User::create([
            'username'      => 'wftpl_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Tpl Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');
        $this->staff->givePermissionTo('view workflow');
    });

    // -------------------------------------------------------------------------
    // index
    // -------------------------------------------------------------------------

    it('index returns every template for an admin with pagination meta', function () {
        WorkflowTemplate::create(['name' => 'A', 'event_type' => 'evA', 'department_id' => $this->dept->id,      'is_active' => true, 'created_by' => $this->admin->id]);
        WorkflowTemplate::create(['name' => 'B', 'event_type' => 'evB', 'department_id' => $this->otherDept->id, 'is_active' => true, 'created_by' => $this->admin->id]);
        WorkflowTemplate::create(['name' => 'C', 'event_type' => 'evC', 'department_id' => null,                 'is_active' => true, 'created_by' => $this->admin->id]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/workflow/templates');

        $response->assertStatus(200)
                 ->assertJsonPath('meta.pagination.total', 3)
                 ->assertJsonStructure(['data', 'meta' => ['pagination']]);
    });

    it('index for non-admin staff scopes to own department only', function () {
        WorkflowTemplate::create(['name' => 'Mine',   'event_type' => 'ev', 'department_id' => $this->dept->id,      'is_active' => true, 'created_by' => $this->admin->id]);
        WorkflowTemplate::create(['name' => 'Others', 'event_type' => 'ev', 'department_id' => $this->otherDept->id, 'is_active' => true, 'created_by' => $this->admin->id]);

        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/workflow/templates');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toBe(['Mine']);
    });

    it('index applies filter.event_type and filter.is_active', function () {
        WorkflowTemplate::create(['name' => 'Active-A',   'event_type' => 'po',     'department_id' => $this->dept->id, 'is_active' => true,  'created_by' => $this->admin->id]);
        WorkflowTemplate::create(['name' => 'Inactive-A', 'event_type' => 'po',     'department_id' => $this->dept->id, 'is_active' => false, 'created_by' => $this->admin->id]);
        WorkflowTemplate::create(['name' => 'Active-B',   'event_type' => 'refund', 'department_id' => $this->dept->id, 'is_active' => true,  'created_by' => $this->admin->id]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/workflow/templates?filter[event_type]=po&filter[is_active]=1');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toBe(['Active-A']);
    });

    it('index applies filter.department_id', function () {
        WorkflowTemplate::create(['name' => 'HR-One',  'event_type' => 'ev', 'department_id' => $this->dept->id,      'is_active' => true, 'created_by' => $this->admin->id]);
        WorkflowTemplate::create(['name' => 'FIN-One', 'event_type' => 'ev', 'department_id' => $this->otherDept->id, 'is_active' => true, 'created_by' => $this->admin->id]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson("/api/v1/workflow/templates?filter[department_id]={$this->otherDept->id}");

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name')->all();
        expect($names)->toBe(['FIN-One']);
    });

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    it('update changes the name/description/is_active and records an Update audit event', function () {
        $tpl = WorkflowTemplate::create([
            'name'          => 'Before',
            'event_type'    => 'ev',
            'department_id' => $this->dept->id,
            'is_active'     => true,
            'created_by'    => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->putJson("/api/v1/workflow/templates/{$tpl->id}", [
            'name'        => 'After',
            'description' => 'New desc',
            'is_active'   => false,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.name', 'After')
                 ->assertJsonPath('data.is_active', false);

        expect(AuditEvent::where('auditable_id', $tpl->id)
            ->where('action', 'update')->exists())->toBeTrue();
    });

    it('patch updates template description and keeps existing name', function () {
        $tpl = WorkflowTemplate::create([
            'name'          => 'Patchable Template',
            'event_type'    => 'ev',
            'department_id' => $this->dept->id,
            'is_active'     => true,
            'created_by'    => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->patchJson("/api/v1/workflow/templates/{$tpl->id}", [
            'description' => 'Patched template description',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.description', 'Patched template description')
                 ->assertJsonPath('data.name', 'Patchable Template');
    });

    // -------------------------------------------------------------------------
    // destroy
    // -------------------------------------------------------------------------

    it('destroy soft-deletes the template and records a Delete audit event', function () {
        $tpl = WorkflowTemplate::create([
            'name'          => 'Doomed',
            'event_type'    => 'ev',
            'department_id' => $this->dept->id,
            'is_active'     => true,
            'created_by'    => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->deleteJson("/api/v1/workflow/templates/{$tpl->id}", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(204);
        expect(WorkflowTemplate::find($tpl->id))->toBeNull();
        expect(AuditEvent::where('auditable_id', $tpl->id)
            ->where('action', 'delete')->exists())->toBeTrue();
    });

    it('destroy returns 403 for a staff user without manage workflow templates permission', function () {
        $tpl = WorkflowTemplate::create([
            'name'          => 'Protected',
            'event_type'    => 'ev',
            'department_id' => $this->dept->id,
            'is_active'     => true,
            'created_by'    => $this->admin->id,
        ]);

        Sanctum::actingAs($this->staff);
        $response = $this->deleteJson("/api/v1/workflow/templates/{$tpl->id}", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403);
        expect(WorkflowTemplate::find($tpl->id))->not->toBeNull();
    });
});
