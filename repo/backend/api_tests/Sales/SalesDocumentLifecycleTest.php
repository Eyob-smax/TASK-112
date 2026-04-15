<?php

use App\Domain\Sales\Enums\SalesStatus;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\InventoryMovement;
use App\Models\SalesDocument;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for the full Sales Document lifecycle:
 * create -> submit -> complete -> void / link-outbound.
 */
describe('Sales Document Lifecycle', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Retail', 'code' => 'RET']);

        $this->manager = User::create([
            'username'      => 'sales_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Sales Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');
        $this->manager->givePermissionTo(['create sales', 'view sales', 'manage sales', 'void sales']);

        $this->staff = User::create([
            'username'      => 'sales_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Sales Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');
        $this->staff->givePermissionTo(['create sales', 'view sales']);

        $this->otherDept = Department::create(['name' => 'Operations', 'code' => 'OPS']);

        $this->outsider = User::create([
            'username'      => 'sales_outsider',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Sales Outsider',
            'department_id' => $this->otherDept->id,
            'is_active'     => true,
        ]);
        $this->outsider->assignRole('staff');
    });

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function createDraftDoc(User $user, Department $dept, string $siteCode = 'STORE1'): array
    {
        Sanctum::actingAs($user);
        $response = test()->postJson('/api/v1/sales', [
            'site_code'     => $siteCode,
            'department_id' => $dept->id,
            'notes'         => 'Test sale',
            'line_items'    => [
                [
                    'product_code' => 'SKU-001',
                    'description'  => 'Widget A',
                    'quantity'     => 2,
                    'unit_price'   => 50.00,
                ],
            ],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $response->assertStatus(201);
        return $response->json('data');
    }

    function createSalesWorkflowTemplate(User $user, Department $dept): array
    {
        Sanctum::actingAs($user);

        $response = test()->postJson('/api/v1/workflow/templates', [
            'name'          => 'Sales Outbound Approval',
            'event_type'    => 'sales_outbound',
            'department_id' => $dept->id,
            'nodes'         => [
                [
                    'node_type'     => 'sequential',
                    'node_order'    => 1,
                    'label'         => 'Outbound Approval',
                    'user_required' => $user->id,
                ],
            ],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(201);

        return $response->json('data');
    }

    function startSalesWorkflowInstance(User $user, string $templateId, string $salesDocumentId): array
    {
        Sanctum::actingAs($user);

        $response = test()->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $templateId,
            'record_type'          => 'sales_document',
            'record_id'            => $salesDocumentId,
            'context'              => [],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(201);

        return $response->json('data');
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    it('creates a sales document and returns 201 with document_number in SITE-YYYYMMDD-000001 format', function () {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/v1/sales', [
            'site_code'     => 'HQ',
            'department_id' => $this->dept->id,
            'notes'         => 'First sale',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', SalesStatus::Draft->value);

        $docNumber = $response->json('data.document_number');
        expect($docNumber)->toMatch('/^HQ-\d{8}-\d{6}$/');
    });

    it('increments sequence for second document on same site and day', function () {
        Sanctum::actingAs($this->manager);

        $first  = createDraftDoc($this->manager, $this->dept, 'SITE2');
        $second = createDraftDoc($this->manager, $this->dept, 'SITE2');

        expect($first['document_number'])->toEndWith('-000001');
        expect($second['document_number'])->toEndWith('-000002');
    });

    it('returns 403 when creating a sales document for a different department', function () {
        Sanctum::actingAs($this->outsider);

        $response = $this->postJson('/api/v1/sales', [
            'site_code'     => 'HQ',
            'department_id' => $this->dept->id,
            'notes'         => 'Cross-department create attempt',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    });

    it('returns 200 with sales document details on show endpoint', function () {
        $doc = createDraftDoc($this->manager, $this->dept, 'SHOW1');

        Sanctum::actingAs($this->manager);

        $response = $this->getJson("/api/v1/sales/{$doc['id']}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $doc['id'])
            ->assertJsonPath('data.line_items.0.product_code', 'SKU-001');
    });

    // -------------------------------------------------------------------------
    // Sensitive-field masking
    // -------------------------------------------------------------------------

    it('masks notes for staff on sales show and list responses', function () {
        // Create document through the API (as manager) to ensure all fields persist
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');
        $createResponse = $this->postJson('/api/v1/sales', [
            'site_code'     => 'MASK1',
            'department_id' => $this->dept->id,
            'notes'         => 'Sensitive sales note',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $createResponse->assertStatus(201);
        $docId = $createResponse->json('data.id');

        // Verify notes persisted in DB
        $dbDoc = \App\Models\SalesDocument::find($docId);
        expect($dbDoc->notes)->toBe('Sensitive sales note');

        // Switch to staff — staff has 'view sales' permission and is in same dept
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        // Staff should see masked notes on list (department-scoped)
        $listResponse = $this->getJson('/api/v1/sales');
        $listResponse->assertStatus(200);
        expect($listResponse->json('data'))->not->toBeEmpty();
        expect($listResponse->json('data.0.notes'))->toBe('[REDACTED]');
    });

    it('does not mask notes for managers on sales show and list responses', function () {
        // Create document through the API to ensure all fields persist
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');
        $createResponse = $this->postJson('/api/v1/sales', [
            'site_code'     => 'MASK1',
            'department_id' => $this->dept->id,
            'notes'         => 'Sensitive sales note',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $createResponse->assertStatus(201);
        $docId = $createResponse->json('data.id');

        // Verify the create response itself includes notes (manager should see them)
        expect($createResponse->json('data.notes'))->toBe('Sensitive sales note');

        // Re-fetch as manager — notes should be visible
        $listResponse = $this->getJson('/api/v1/sales');
        $listResponse->assertStatus(200);
        expect($listResponse->json('data.0.notes'))->toBe('Sensitive sales note');
    });

    // -------------------------------------------------------------------------
    // Submit (draft -> reviewed)
    // -------------------------------------------------------------------------

    it('returns 200 on submit draft->reviewed', function () {
        $doc = createDraftDoc($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/sales/{$doc['id']}/submit", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', SalesStatus::Reviewed->value);
    });

    // -------------------------------------------------------------------------
    // Complete (reviewed -> completed)
    // -------------------------------------------------------------------------

    it('returns 200 on complete reviewed->completed and creates inventory movements', function () {
        $doc = createDraftDoc($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/v1/sales/{$doc['id']}/submit", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);

        $response = $this->postJson("/api/v1/sales/{$doc['id']}/complete", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', SalesStatus::Completed->value);

        $movements = InventoryMovement::where('sales_document_id', $doc['id'])->get();
        expect($movements->count())->toBeGreaterThan(0);
        expect($movements->first()->quantity_delta)->toBeLessThan(0);

        // Each inventory movement must have a corresponding audit event (compliance requirement).
        $movementIds = $movements->pluck('id');
        $auditCount  = AuditEvent::whereIn('auditable_id', $movementIds)->count();
        expect($auditCount)->toBe($movementIds->count());
    });

    it('returns 409 with invalid_sales_transition when completing a draft document', function () {
        $doc = createDraftDoc($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/sales/{$doc['id']}/complete", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'invalid_sales_transition');
    });

    // -------------------------------------------------------------------------
    // Void
    // -------------------------------------------------------------------------

    it('returns 200 on void with a reason', function () {
        $doc = createDraftDoc($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/sales/{$doc['id']}/void", [
            'reason' => 'Customer cancelled order.',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', SalesStatus::Voided->value);
    });

    it('returns 409 with invalid_sales_transition when voiding a completed document', function () {
        $doc = createDraftDoc($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/v1/sales/{$doc['id']}/submit", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);
        $this->postJson("/api/v1/sales/{$doc['id']}/complete", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);

        $response = $this->postJson("/api/v1/sales/{$doc['id']}/void", [
            'reason' => 'Trying to void a completed doc.',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'invalid_sales_transition');
    });

    // -------------------------------------------------------------------------
    // Outbound linkage
    // -------------------------------------------------------------------------

    it('returns 409 with outbound_linkage_not_allowed when linking outbound before completion', function () {
        $doc = createDraftDoc($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/sales/{$doc['id']}/link-outbound", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'outbound_linkage_not_allowed');
    });

    it('returns 409 with outbound_linkage_not_allowed when no workflow instance is linked', function () {
        $doc = createDraftDoc($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/v1/sales/{$doc['id']}/submit", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);
        $this->postJson("/api/v1/sales/{$doc['id']}/complete", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);

        $response = $this->postJson("/api/v1/sales/{$doc['id']}/link-outbound", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'outbound_linkage_not_allowed');
    });

    it('allows outbound linkage on completed document with an approved workflow and returns 200', function () {
        $doc = createDraftDoc($this->manager, $this->dept);
        $template = createSalesWorkflowTemplate($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/v1/sales/{$doc['id']}/submit", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);
        $this->postJson("/api/v1/sales/{$doc['id']}/complete", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);

        $instance = startSalesWorkflowInstance($this->manager, $template['id'], $doc['id']);
        $nodeId = $instance['nodes'][0]['id'];

        $this->postJson("/api/v1/workflow/nodes/{$nodeId}/approve", [], ['X-Idempotency-Key' => Str::uuid()->toString()])
            ->assertStatus(200)
            ->assertJsonPath('data.instance_status', WorkflowStatus::Approved->value);

        $response = $this->postJson("/api/v1/sales/{$doc['id']}/link-outbound", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200);
        expect($response->json('data.outbound_linked_at'))->not->toBeNull();
    });

    it('returns 409 when outbound linkage is attempted on a document with a non-approved workflow instance', function () {
        $doc = createDraftDoc($this->manager, $this->dept);
        $template = createSalesWorkflowTemplate($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $this->postJson("/api/v1/sales/{$doc['id']}/submit", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);
        $this->postJson("/api/v1/sales/{$doc['id']}/complete", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);

        startSalesWorkflowInstance($this->manager, $template['id'], $doc['id']);

        $response = $this->postJson("/api/v1/sales/{$doc['id']}/link-outbound", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'outbound_linkage_not_allowed');
    });

    it('returns 409 with workflow_instance_already_linked when starting a second workflow for the same sales document', function () {
        $doc = createDraftDoc($this->manager, $this->dept);
        $template = createSalesWorkflowTemplate($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        startSalesWorkflowInstance($this->manager, $template['id'], $doc['id']);

        $response = $this->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $template['id'],
            'record_type'          => 'sales_document',
            'record_id'            => $doc['id'],
            'context'              => [],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'workflow_instance_already_linked');
    });

    it('soft-deletes a sales document and returns 204', function () {
        $doc = createDraftDoc($this->manager, $this->dept, 'DEL1');

        Sanctum::actingAs($this->manager);

        $response = $this->deleteJson("/api/v1/sales/{$doc['id']}", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(204);

        // Verify the sales document is soft-deleted in the database
        $this->assertSoftDeleted('sales_documents', ['id' => $doc['id']]);
    });

    it('returns 404 when accessing a soft-deleted sales document', function () {
        $doc = createDraftDoc($this->manager, $this->dept, 'DEL2');

        Sanctum::actingAs($this->manager);

        $this->deleteJson("/api/v1/sales/{$doc['id']}", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ])->assertStatus(204);

        $response = $this->getJson("/api/v1/sales/{$doc['id']}");
        $response->assertStatus(404);
    });

    it('returns 403 when a user from a different department tries to delete a sales document', function () {
        $doc = createDraftDoc($this->manager, $this->dept, 'DEL3');

        Sanctum::actingAs($this->outsider);
        $this->outsider->givePermissionTo('manage sales');

        $response = $this->deleteJson("/api/v1/sales/{$doc['id']}", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        // Outsider has the permission but wrong department → object-level 403
        $response->assertStatus(403);
    });
});
