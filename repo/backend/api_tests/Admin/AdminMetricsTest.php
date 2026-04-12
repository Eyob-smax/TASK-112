<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\MetricsSnapshot;
use App\Models\StructuredLog;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for admin metrics and log endpoints.
 *
 * Covers: metrics snapshot retrieval (raw + summary), structured log browsing,
 *         health endpoint, failed-login inspection, approval backlog, authorization.
 */
describe('Admin Metrics and Log Endpoints', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Metrics Dept', 'code' => 'MTR']);

        $this->admin = User::create([
            'username'      => 'metrics_admin',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Metrics Admin',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->admin->assignRole('admin');

        $this->auditor = User::create([
            'username'      => 'log_auditor',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Log Auditor',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->auditor->assignRole('auditor');

        $this->staff = User::create([
            'username'      => 'plain_staff_m',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Plain Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');

        $this->workflowTargetDocument = Document::create([
            'title'                => 'Metrics Workflow Target',
            'document_type'        => 'report',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->admin->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);

        // Seed metrics snapshots
        MetricsSnapshot::create([
            'metric_type'    => 'request_timing',
            'value'          => 120.5,
            'labels'         => ['route' => '/api/v1/sales'],
            'recorded_at'    => now()->subHours(2),
            'retained_until' => now()->addDays(90),
        ]);
        MetricsSnapshot::create([
            'metric_type'    => 'queue_depth',
            'value'          => 5.0,
            'labels'         => [],
            'recorded_at'    => now()->subHours(1),
            'retained_until' => now()->addDays(90),
        ]);
        MetricsSnapshot::create([
            'metric_type'    => 'failed_approvals',
            'value'          => 2.0,
            'labels'         => ['department_id' => $this->dept->id],
            'recorded_at'    => now(),
            'retained_until' => now()->addDays(90),
        ]);
    });

    // -------------------------------------------------------------------------
    // GET /admin/metrics — raw listing
    // -------------------------------------------------------------------------

    it('returns 200 with paginated metrics for admin role', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'metric_type', 'value', 'labels', 'recorded_at', 'retained_until']],
                'meta' => ['retention_days', 'pagination'],
            ]);

        expect($response->json('meta.pagination.total'))->toBe(3);
    });

    it('filters metrics by metric_type', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/metrics?metric_type=queue_depth');

        $response->assertStatus(200);
        $data = $response->json('data');
        expect(count($data))->toBe(1)
            ->and($data[0]['metric_type'])->toBe('queue_depth');
    });

    it('returns summary when summary=1 is passed', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/metrics?summary=1');

        $response->assertStatus(200);
        $data = $response->json('data');
        // Should have one entry per metric_type
        $types = collect($data)->pluck('metric_type')->sort()->values()->toArray();
        expect($types)->toBe(['failed_approvals', 'queue_depth', 'request_timing']);

        // Check summary fields exist
        $first = collect($data)->firstWhere('metric_type', 'request_timing');
        expect($first)->toHaveKeys(['metric_type', 'sample_count', 'avg_value', 'min_value', 'max_value', 'last_recorded_at'])
            ->and($first['sample_count'])->toBe(1)
            ->and($first['avg_value'])->toBe(120.5);
    });

    it('returns 403 for non-admin on metrics endpoint', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/admin/metrics');

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // GET /admin/logs
    // -------------------------------------------------------------------------

    it('returns 200 with paginated logs for admin role', function () {
        StructuredLog::create([
            'level'          => 'info',
            'message'        => 'Test log entry',
            'channel'        => 'application',
            'recorded_at'    => now(),
            'retained_until' => now()->addDays(90),
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'level', 'message', 'channel', 'recorded_at']],
                'meta' => ['retention_days', 'pagination'],
            ]);
    });

    it('allows auditor role to view structured logs', function () {
        Sanctum::actingAs($this->auditor);
        $response = $this->getJson('/api/v1/admin/logs');

        $response->assertStatus(200);
    });

    it('filters logs by level', function () {
        StructuredLog::create([
            'level' => 'error', 'message' => 'Error log', 'channel' => 'application',
            'recorded_at' => now(), 'retained_until' => now()->addDays(90),
        ]);
        StructuredLog::create([
            'level' => 'info', 'message' => 'Info log', 'channel' => 'application',
            'recorded_at' => now(), 'retained_until' => now()->addDays(90),
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/logs?filter[level]=error');

        $response->assertStatus(200);
        $data = $response->json('data');
        expect(count($data))->toBe(1)
            ->and($data[0]['level'])->toBe('error');
    });

    it('returns 403 for plain staff on logs endpoint', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/admin/logs');

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // GET /admin/health
    // -------------------------------------------------------------------------

    it('returns 200 with health status for admin role', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'checks' => ['database', 'queue', 'storage', 'backup'],
                    'app'    => ['version', 'environment', 'timezone'],
                    'retention',
                    'checked_at',
                ],
            ]);

        expect($response->json('data.checks.database.status'))->toBe('ok');
        expect($response->json('data.checks.queue.status'))->toBe('ok');
    });

    it('returns 403 for non-admin on health endpoint', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/admin/health');

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // GET /admin/failed-logins
    // -------------------------------------------------------------------------

    it('returns 200 with failed login attempts for admin', function () {
        \App\Models\FailedLoginAttempt::create([
            'user_id'             => $this->staff->id,
            'username_attempted'  => 'plain_staff_m',
            'ip_address'          => '192.168.1.5',
            'attempted_at'        => now()->subMinutes(5),
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/failed-logins');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'user_id', 'username_attempted', 'ip_address', 'attempted_at']],
                'meta' => ['pagination'],
            ]);

        expect($response->json('meta.pagination.total'))->toBe(1);
    });

    it('returns 403 for non-admin on failed-logins endpoint', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/admin/failed-logins');

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // GET /admin/approval-backlog
    // -------------------------------------------------------------------------

    it('returns 200 with approval backlog for admin role', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/approval-backlog');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['pagination'],
            ]);
    });

    it('returns only overdue nodes when overdue_only=1 is set', function () {
        $template = \App\Models\WorkflowTemplate::create([
            'name'       => 'Test Template',
            'event_type' => 'test',
            'is_active'  => true,
            'created_by' => $this->admin->id,
        ]);
        $instance = \App\Models\WorkflowInstance::create([
            'workflow_template_id' => $template->id,
            'record_type'          => 'test',
            'record_id'            => $this->admin->id,
            'status'               => 'in_progress',
            'initiated_by'         => $this->admin->id,
            'started_at'           => now(),
        ]);
        // Overdue node
        \App\Models\WorkflowNode::create([
            'workflow_instance_id' => $instance->id,
            'node_order'           => 1,
            'node_type'            => 'sequential',
            'status'               => 'pending',
            'sla_due_at'           => now()->subDay(),
            'assigned_to'          => $this->admin->id,
        ]);
        // Not-yet-due node
        \App\Models\WorkflowNode::create([
            'workflow_instance_id' => $instance->id,
            'node_order'           => 2,
            'node_type'            => 'sequential',
            'status'               => 'pending',
            'sla_due_at'           => now()->addDays(2),
            'assigned_to'          => $this->admin->id,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/approval-backlog?overdue_only=1');

        $response->assertStatus(200);
        expect($response->json('meta.pagination.total'))->toBe(1)
            ->and($response->json('data.0.is_overdue'))->toBeTrue();
    });

    it('returns 403 for plain staff on approval-backlog endpoint', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/admin/approval-backlog');

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // GET /admin/locked-accounts
    // -------------------------------------------------------------------------

    it('returns 403 for non-admin on locked-accounts endpoint', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/admin/locked-accounts');

        $response->assertStatus(403);
    });

    it('returns 403 for auditor role on locked-accounts endpoint', function () {
        Sanctum::actingAs($this->auditor);
        $response = $this->getJson('/api/v1/admin/locked-accounts');

        $response->assertStatus(403);
    });

    it('returns 200 with empty data when no accounts are locked', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/locked-accounts');

        $response->assertStatus(200)
                 ->assertJsonStructure(['data']);
        expect($response->json('data'))->toBeArray()->toHaveCount(0);
    });

    it('returns locked accounts with required fields for admin', function () {
        // Lock the staff user
        $this->staff->update([
            'locked_until'         => now()->addMinutes(15),
            'failed_attempt_count' => 5,
            'last_failed_at'       => now()->subMinutes(1),
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/locked-accounts');

        $response->assertStatus(200);
        $data = $response->json('data');

        expect($data)->toHaveCount(1);
        expect($data[0])->toHaveKeys([
            'id', 'username', 'email', 'display_name',
            'locked_until', 'failed_attempt_count', 'last_failed_at',
        ]);
        expect($data[0]['id'])->toBe($this->staff->id)
            ->and($data[0]['failed_attempt_count'])->toBe(5);
    });

    // -------------------------------------------------------------------------
    // Producer-path instrumentation: metrics written by middleware and service
    // -------------------------------------------------------------------------

    it('records a request_timing snapshot after each authenticated request via middleware', function () {
        Sanctum::actingAs($this->admin);

        $countBefore = MetricsSnapshot::where('metric_type', 'request_timing')->count();

        // Any authenticated request must trigger RecordRequestTimingMiddleware
        $this->getJson('/api/v1/admin/health')->assertStatus(200);

        $countAfter = MetricsSnapshot::where('metric_type', 'request_timing')->count();
        expect($countAfter)->toBeGreaterThan($countBefore);
    });

    it('writes a structured log entry for mutating authenticated requests via middleware', function () {
        Sanctum::actingAs($this->admin);

        $countBefore = StructuredLog::count();

        $this->postJson('/api/v1/auth/logout', [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ])->assertStatus(204);

        $countAfter = StructuredLog::count();
        expect($countAfter)->toBeGreaterThan($countBefore);

        $latest = StructuredLog::orderByDesc('recorded_at')->first();
        expect($latest)->not->toBeNull();
        expect($latest->channel)->toBe('api');
        expect($latest->context['method'])->toBe('POST');
        expect($latest->context['path'])->toBe('api/v1/auth/logout');
    });

    it('records a failed_approvals snapshot when a workflow node is rejected', function () {
        $this->admin->givePermissionTo([
            'manage workflow templates',
            'manage workflow instances',
            'approve workflow nodes',
            'view workflow',
        ]);
        Sanctum::actingAs($this->admin);

        // Create a minimal template and instance to drive through rejection
        $templateResponse = $this->postJson('/api/v1/workflow/templates', [
            'name'       => 'Metrics Producer Template',
            'event_type' => 'metrics_test',
            'nodes'      => [
                ['node_type' => 'sequential', 'node_order' => 1, 'label' => 'Review'],
            ],
        ]);
        $templateResponse->assertStatus(201);
        $templateId = $templateResponse->json('data.id');

        $instanceResponse = $this->postJson('/api/v1/workflow/instances', [
            'workflow_template_id' => $templateId,
            'record_type'          => 'document',
            'record_id'            => $this->workflowTargetDocument->id,
            'context'              => [],
        ]);
        $instanceResponse->assertStatus(201);
        $nodeId = $instanceResponse->json('data.nodes.0.id');

        $countBefore = MetricsSnapshot::where('metric_type', 'failed_approvals')->count();

        $this->postJson("/api/v1/workflow/nodes/{$nodeId}/reject", [
            'reason' => 'Test rejection for metrics instrumentation.',
        ])->assertStatus(200);

        $countAfter = MetricsSnapshot::where('metric_type', 'failed_approvals')->count();
        expect($countAfter)->toBeGreaterThan($countBefore);
    });

    it('does not return accounts whose lock has expired', function () {
        // Lock expired in the past
        $this->staff->update([
            'locked_until'         => now()->subMinutes(1),
            'failed_attempt_count' => 5,
        ]);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/locked-accounts');

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(0);
    });
});
