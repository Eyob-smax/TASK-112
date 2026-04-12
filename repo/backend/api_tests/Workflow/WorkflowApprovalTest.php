<?php

use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowNode;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for Workflow Engine — template creation, instance lifecycle, and approval actions.
 */
describe('Workflow Approval', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Finance', 'code' => 'FIN']);

        $this->manager = User::create([
            'username'      => 'wf_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'WF Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');
        $this->manager->givePermissionTo([
            'manage workflow templates',
            'manage workflow instances',
            'view workflow',
            'approve workflow nodes',
        ]);

        $this->staff = User::create([
            'username'      => 'wf_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'WF Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');
        $this->staff->givePermissionTo([
            'view workflow',
            'approve workflow nodes',
        ]);

        $this->targetDocument = Document::create([
            'title'                => 'Workflow Target Document',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);
    });

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function createTemplate(User $manager, Department $dept): array
    {
        Sanctum::actingAs($manager);
        $response = test()->postJson('/api/v1/workflow/templates', [
            'name'          => 'Finance Approval',
            'event_type'    => 'expense_request',
            'department_id' => $dept->id,
            'nodes'         => [
                [
                    'node_type'  => 'sequential',
                    'node_order' => 1,
                    'label'      => 'Manager Review',
                ],
            ],
        ]);
        $response->assertStatus(201);
        return $response->json('data');
    }

    function startInstance(User $manager, string $templateId, string $recordId): array
    {
        Sanctum::actingAs($manager);
        $response = test()->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $templateId,
            'record_type'          => 'document',
            'record_id'            => $recordId,
            'context'              => ['amount' => 500],
        ]);
        $response->assertStatus(201);
        return $response->json('data');
    }

    // -------------------------------------------------------------------------
    // Template CRUD
    // -------------------------------------------------------------------------

    it('creates a workflow template and returns 201 with nodes', function () {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/v1/workflow/templates', [
            'name'          => 'Purchase Approval',
            'event_type'    => 'purchase_order',
            'department_id' => $this->dept->id,
            'nodes'         => [
                [
                    'node_type'         => 'sequential',
                    'node_order'        => 1,
                    'sla_business_days' => 2,
                    'label'             => 'Initial Review',
                ],
                [
                    'node_type'         => 'sequential',
                    'node_order'        => 2,
                    'sla_business_days' => 3,
                    'label'             => 'Director Approval',
                ],
            ],
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.name', 'Purchase Approval')
                 ->assertJsonPath('data.event_type', 'purchase_order');

        expect(count($response->json('data.nodes')))->toBe(2);
    });

    // -------------------------------------------------------------------------
    // Instance lifecycle
    // -------------------------------------------------------------------------

    it('starts a workflow instance and returns 201 with in_progress status', function () {
        $template = createTemplate($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $template['id'],
            'record_type'          => 'document',
            'record_id'            => $this->targetDocument->id,
            'context'              => [],
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', WorkflowStatus::InProgress->value);
    });

    it('returns 422 when record_type is outside the supported allow-list', function () {
        $template = createTemplate($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $template['id'],
            'record_type'          => 'expense_request',
            'record_id'            => $this->targetDocument->id,
            'context'              => [],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'validation_error');
    });

    it('returns 404 when the workflow target record does not exist', function () {
        $template = createTemplate($this->manager, $this->dept);

        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $template['id'],
            'record_type'          => 'document',
            'record_id'            => Str::uuid()->toString(),
            'context'              => [],
        ]);

        $response->assertStatus(404);
    });

    it('enforces DB check constraints on workflow status and node_type columns', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $instanceId = $instance['id'];
        $nodeId     = $instance['nodes'][0]['id'];

        expect(fn () => WorkflowInstance::whereKey($instanceId)->update([
            'status' => 'invalid_state',
        ]))->toThrow(QueryException::class);

        expect(fn () => WorkflowNode::whereKey($nodeId)->update([
            'status' => 'invalid_state',
        ]))->toThrow(QueryException::class);

        expect(fn () => WorkflowNode::whereKey($nodeId)->update([
            'node_type' => 'invalid_type',
        ]))->toThrow(QueryException::class);
    });

    it('approves a workflow node and advances instance to approved on final node', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/approve");

        $response->assertStatus(200);

        // Instance should now be approved
        $instanceResponse = $this->getJson("/api/v1/workflow/instances/{$instance['id']}");
        $instanceResponse->assertStatus(200)
                         ->assertJsonPath('data.status', WorkflowStatus::Approved->value);
    });


    it('rejects a workflow node with mandatory reason and marks instance as rejected', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/reject", [
            'reason' => 'Budget exceeded for this quarter.',
        ]);

        $response->assertStatus(200);

        $instanceResponse = $this->getJson("/api/v1/workflow/instances/{$instance['id']}");
        $instanceResponse->assertStatus(200)
                         ->assertJsonPath('data.status', WorkflowStatus::Rejected->value);
    });


    it('returns 422 with reason_required when rejecting without a reason', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/reject", [
            'reason' => '',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'reason_required');
    });

    it('reassigns a workflow node to a different user', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/reassign", [
            'target_user_id' => $this->staff->id,
            'reason'         => 'Staff is more appropriate for this request.',
        ]);

        $response->assertStatus(200);

        // Verify node now assigned to staff
        $nodeResponse = $this->getJson("/api/v1/workflow/nodes/{$nodeId}");
        $nodeResponse->assertStatus(200)
                     ->assertJsonPath('data.assigned_to', $this->staff->id);
    });

    it('returns 422 validation_error when reassign is called without target_user_id', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/reassign", [
            'reason' => 'Needs a different approver.',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    });

    it('adds an approver and creates a new parallel node', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/add-approver", [
            'target_user_id' => $this->staff->id,
        ]);

        $response->assertStatus(200);

        // The instance should now have 2 nodes (original + new parallel)
        $instanceResponse = $this->getJson("/api/v1/workflow/instances/{$instance['id']}");
        $instanceResponse->assertStatus(200);
        expect(count($instanceResponse->json('data.nodes')))->toBe(2);
    });


    it('returns 422 validation_error when add-approver is called without target_user_id', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/add-approver", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_error');
    });

    // -------------------------------------------------------------------------
    // Authorization — WorkflowInstancePolicy::approve() enforcement
    // -------------------------------------------------------------------------

    it('returns 403 when a user without approve workflow nodes permission tries to approve a node', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        // Create a user with only view workflow permission — cannot approve
        $viewer = User::create([
            'username'      => 'wf_viewer_only',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'WF Viewer Only',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $viewer->assignRole('auditor'); // auditor has view workflow but NOT approve workflow nodes

        Sanctum::actingAs($viewer);

        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/approve");

        $response->assertStatus(403);
    });

    it('returns 403 when a user with approve permission but not the assigned approver tries to approve', function () {
        // Create a template with user_required set to the manager — node.assigned_to = manager.id
        Sanctum::actingAs($this->manager);
        $templateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'          => 'Assigned Approval Test',
            'event_type'    => 'assigned_expense',
            'department_id' => $this->dept->id,
            'nodes'         => [
                [
                    'node_type'     => 'sequential',
                    'node_order'    => 1,
                    'label'         => 'Manager Only',
                    'user_required' => $this->manager->id, // explicitly assigned to manager
                ],
            ],
        ]);
        $templateResponse->assertStatus(201);
        $template = $templateResponse->json('data');

        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);
        $nodeId   = $instance['nodes'][0]['id'];

        // Staff has 'approve workflow nodes' permission but is NOT the assigned user
        Sanctum::actingAs($this->staff);

        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/approve");

        // Must be 403 — staff has the permission but is not the assignee
        $response->assertStatus(403);
    });

    it('returns 403 when a user who is not the initiator or assignee tries to view a workflow instance', function () {
        // Manager creates an instance — is the initiator with no specific assignee
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        // Create a foreign user with view workflow permission but no relationship to this instance
        $foreignUser = User::create([
            'username'      => 'wf_foreign_viewer',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'Foreign Viewer',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $foreignUser->assignRole('staff');
        $foreignUser->givePermissionTo('view workflow'); // has permission, but not initiator or assignee

        Sanctum::actingAs($foreignUser);

        $response = $this->getJson("/api/v1/workflow/instances/{$instance['id']}");

        // Not initiator, not assignee, not admin/manager → 403
        $response->assertStatus(403);
    });

    it('returns 403 when a user without view workflow permission tries to view a node', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        // viewer role has no workflow permissions
        $viewer = User::create([
            'username'      => 'no_wf_perm',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'No WF Perm',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $viewer->assignRole('viewer');

        Sanctum::actingAs($viewer);

        $response = $this->getJson("/api/v1/workflow/nodes/{$nodeId}");

        $response->assertStatus(403);
    });

    it('returns 403 when a user has view workflow permission but is unrelated to the node instance', function () {
        // Create instance initiated by manager; node assigned to manager
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $nodeId = $instance['nodes'][0]['id'];

        // Unrelated user: has 'view workflow' permission but is neither initiator nor assignee
        $unrelated = User::create([
            'username'      => 'unrelated_wf_viewer',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'Unrelated Viewer',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $unrelated->assignRole('staff');
        $unrelated->givePermissionTo('view workflow');

        Sanctum::actingAs($unrelated);

        $response = $this->getJson("/api/v1/workflow/nodes/{$nodeId}");

        // Has permission but not initiator or assignee and not manager/admin → 403
        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // Cross-department template isolation (WorkflowTemplatePolicy scope enforcement)
    // -------------------------------------------------------------------------

    it('returns 403 when a user from a different department views a dept-scoped template', function () {
        // Manager creates a template scoped to their department
        Sanctum::actingAs($this->manager);
        $templateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'          => 'Dept-Scoped Template',
            'event_type'    => 'dept_scoped_event',
            'department_id' => $this->dept->id,
            'nodes'         => [
                ['node_type' => 'sequential', 'node_order' => 1, 'label' => 'Review'],
            ],
        ]);
        $templateResponse->assertStatus(201);
        $templateId = $templateResponse->json('data.id');

        // User from a different department — has manage workflow templates permission
        $otherDept = Department::create(['name' => 'Procurement', 'code' => 'PRO']);
        $otherUser = User::create([
            'username'      => 'wf_other_dept_viewer',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'Other Dept Viewer',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $otherUser->assignRole('staff');
        $otherUser->givePermissionTo('manage workflow templates');

        Sanctum::actingAs($otherUser);

        $response = $this->getJson("/api/v1/workflow/templates/{$templateId}");

        // Has permission but is in the wrong department → 403
        $response->assertStatus(403);
    });

    it('returns 403 when a user from a different department updates a dept-scoped template', function () {
        // Manager creates a template scoped to their department
        Sanctum::actingAs($this->manager);
        $templateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'          => 'Dept-Scoped Update Template',
            'event_type'    => 'dept_scoped_update',
            'department_id' => $this->dept->id,
            'nodes'         => [
                ['node_type' => 'sequential', 'node_order' => 1, 'label' => 'Approve'],
            ],
        ]);
        $templateResponse->assertStatus(201);
        $templateId = $templateResponse->json('data.id');

        // User from a different department — has manage workflow templates permission
        $otherDept = Department::create(['name' => 'Logistics', 'code' => 'LOG']);
        $otherUser = User::create([
            'username'      => 'wf_other_dept_updater',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'Other Dept Updater',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $otherUser->assignRole('staff');
        $otherUser->givePermissionTo('manage workflow templates');

        Sanctum::actingAs($otherUser);

        $response = $this->putJson("/api/v1/workflow/templates/{$templateId}", [
            'name' => 'Tampered Name',
        ]);

        // Has permission but is in the wrong department → 403
        $response->assertStatus(403);
    });

    it('lists only department-scoped templates for non cross-scope users', function () {
        Sanctum::actingAs($this->manager);

        $ownTemplateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'          => 'Own Department Template',
            'event_type'    => 'own_department_event',
            'department_id' => $this->dept->id,
            'nodes'         => [
                ['node_type' => 'sequential', 'node_order' => 1, 'label' => 'Own Review'],
            ],
        ]);
        $ownTemplateResponse->assertStatus(201);
        $ownTemplateId = $ownTemplateResponse->json('data.id');

        $otherDept = Department::create(['name' => 'Operations', 'code' => 'OPS3']);
        $otherManager = User::create([
            'username'      => 'wf_other_manager',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'Other Manager',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $otherManager->assignRole('manager');

        Sanctum::actingAs($otherManager);

        $otherTemplateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'          => 'Other Department Template',
            'event_type'    => 'other_department_event',
            'department_id' => $otherDept->id,
            'nodes'         => [
                ['node_type' => 'sequential', 'node_order' => 1, 'label' => 'Other Review'],
            ],
        ]);
        $otherTemplateResponse->assertStatus(201);
        $otherTemplateId = $otherTemplateResponse->json('data.id');

        $systemTemplateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'          => 'System Template',
            'event_type'    => 'system_event',
            'department_id' => null,
            'nodes'         => [
                ['node_type' => 'sequential', 'node_order' => 1, 'label' => 'System Review'],
            ],
        ]);
        $systemTemplateResponse->assertStatus(201);
        $systemTemplateId = $systemTemplateResponse->json('data.id');

        Sanctum::actingAs($this->staff);

        $response = $this->getJson('/api/v1/workflow/templates');

        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();

        expect($ids)->toContain($ownTemplateId);
        expect($ids)->not->toContain($otherTemplateId);
        expect($ids)->not->toContain($systemTemplateId);
    });

    // -------------------------------------------------------------------------
    // Sequential ordering enforcement (BLOCKER-2)
    // -------------------------------------------------------------------------

    it('returns 409 with node_not_actionable when attempting to approve a later-order node before earlier ones complete', function () {
        // Create a 2-node sequential template
        Sanctum::actingAs($this->manager);

        $templateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'          => 'Sequential Order Test',
            'event_type'    => 'order_test',
            'department_id' => $this->dept->id,
            'nodes'         => [
                ['node_type' => 'sequential', 'node_order' => 1, 'label' => 'Step One'],
                ['node_type' => 'sequential', 'node_order' => 2, 'label' => 'Step Two'],
            ],
        ]);
        $templateResponse->assertStatus(201);
        $template = $templateResponse->json('data');

        $instanceResponse = $this->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $template['id'],
            'record_type'          => 'document',
            'record_id'            => $this->targetDocument->id,
            'context'              => [],
        ]);
        $instanceResponse->assertStatus(201);
        $instance = $instanceResponse->json('data');

        // Find the node at node_order=2 (the later node)
        $node2 = collect($instance['nodes'])->firstWhere('node_order', 2);
        expect($node2)->not->toBeNull();

        // Attempt to approve node 2 before node 1 has been approved
        $response = $this->postJson("/api/v1/workflow/nodes/{$node2['id']}/approve");

        // Must be rejected — predecessor (node_order=1) is still pending
        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'node_not_actionable');
    });

    // -------------------------------------------------------------------------
    // Withdraw
    // -------------------------------------------------------------------------

    it('withdraws a workflow instance and cancels pending nodes', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/workflow/instances/{$instance['id']}/withdraw", [
            'reason' => 'Request no longer needed.',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', WorkflowStatus::Withdrawn->value);
    });


    it('returns 403 when a non-initiator without workflow-instance permission withdraws', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        Sanctum::actingAs($this->staff);

        $response = $this->postJson("/api/v1/workflow/instances/{$instance['id']}/withdraw", [
            'reason' => 'Not authorized.',
        ]);

        $response->assertStatus(403);
    });

    it('allows a user with manage workflow instances permission to withdraw as non-initiator', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        $operator = User::create([
            'username'      => 'wf_operator',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'WF Operator',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $operator->assignRole('staff');
        $operator->givePermissionTo('manage workflow instances');

        Sanctum::actingAs($operator);

        $response = $this->postJson("/api/v1/workflow/instances/{$instance['id']}/withdraw", [
            'reason' => 'Operational cancellation.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', WorkflowStatus::Withdrawn->value);
    });

    it('returns 409 with workflow_terminated when acting on a withdrawn instance', function () {
        $template = createTemplate($this->manager, $this->dept);
        $instance = startInstance($this->manager, $template['id'], $this->targetDocument->id);

        Sanctum::actingAs($this->manager);

        // Withdraw
        $this->postJson("/api/v1/workflow/instances/{$instance['id']}/withdraw", [
            'reason' => 'No longer needed.',
        ])->assertStatus(200);

        // Try to approve a node after withdrawal
        $nodeId = $instance['nodes'][0]['id'];
        $response = $this->postJson("/api/v1/workflow/nodes/{$nodeId}/approve");

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'workflow_terminated');
    });

    // -------------------------------------------------------------------------
    // Template applicability enforcement
    // -------------------------------------------------------------------------

    it('returns 422 with workflow_template_not_applicable when context event_type does not match template', function () {
        Sanctum::actingAs($this->manager);

        // Create a template scoped to 'document_review' event type
        $templateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'          => 'Document Review Template',
            'event_type'    => 'document_review',
            'department_id' => $this->dept->id,
            'nodes'         => [
                ['node_type' => 'sequential', 'node_order' => 1, 'label' => 'Review'],
            ],
        ]);
        $templateResponse->assertStatus(201);
        $templateId = $templateResponse->json('data.id');

        // Attempt to start an instance supplying a mismatched event_type in context
        $response = $this->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $templateId,
            'record_type'          => 'document',
            'record_id'            => $this->targetDocument->id,
            'context'              => ['event_type' => 'expense_request'],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'workflow_template_not_applicable');
    });

    it('returns 422 with workflow_template_not_applicable when context amount is below template minimum threshold', function () {
        Sanctum::actingAs($this->manager);

        // Create a template with an amount_threshold_min of 1000
        $templateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'                 => 'High-Value Approval',
            'event_type'           => 'purchase_request',
            'department_id'        => $this->dept->id,
            'amount_threshold_min' => 1000,
            'nodes'                => [
                ['node_type' => 'sequential', 'node_order' => 1, 'label' => 'Finance Review'],
            ],
        ]);
        $templateResponse->assertStatus(201);
        $templateId = $templateResponse->json('data.id');

        // Attempt to start with an amount below the minimum threshold
        $response = $this->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $templateId,
            'record_type'          => 'document',
            'record_id'            => $this->targetDocument->id,
            'context'              => ['amount' => 500],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'workflow_template_not_applicable');
    });
});
