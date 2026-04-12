<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for immutable audit event browsing and filtering.
 */
describe('Audit Event Browsing', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Audit Dept', 'code' => 'AUD']);

        $this->admin = User::create([
            'username'      => 'audit_admin',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Audit Admin',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->admin->assignRole('admin');

        $this->auditor = User::create([
            'username'      => 'auditor_user',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Auditor',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->auditor->assignRole('auditor');

        $this->staff = User::create([
            'username'      => 'plain_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');
    });

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    function makeAuditEvent(AuditAction $action, ?string $actorId = null, string $ip = '127.0.0.1', ?\Illuminate\Support\Carbon $createdAt = null): AuditEvent
    {
        $event = new AuditEvent();
        $event->correlation_id = (string) Str::uuid();
        $event->action         = $action;
        $event->actor_id       = $actorId;
        $event->ip_address     = $ip;
        $event->created_at     = $createdAt ?? now();
        $event->save();
        return $event;
    }

    // -------------------------------------------------------------------------
    // List endpoint
    // -------------------------------------------------------------------------

    it('returns 200 with paginated audit events for admin role', function () {
        makeAuditEvent(AuditAction::Login, $this->admin->id);
        makeAuditEvent(AuditAction::Create, $this->admin->id);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/audit/events');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'correlation_id', 'actor_id', 'action', 'ip_address', 'created_at']],
                'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']],
            ]);

        expect($response->json('meta.pagination.total'))->toBeGreaterThanOrEqual(2);
    });

    it('returns 200 with paginated audit events for auditor role', function () {
        makeAuditEvent(AuditAction::Approve, $this->auditor->id);

        Sanctum::actingAs($this->auditor);
        $response = $this->getJson('/api/v1/audit/events');

        $response->assertStatus(200);
    });

    it('returns 403 for non-admin non-auditor users', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/audit/events');

        $response->assertStatus(403);
    });

    it('filters audit events by action', function () {
        makeAuditEvent(AuditAction::Login, $this->admin->id);
        makeAuditEvent(AuditAction::Approve, $this->admin->id);
        makeAuditEvent(AuditAction::Reject, $this->admin->id);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/audit/events?filter[action]=approve');

        $response->assertStatus(200);
        $data = $response->json('data');
        expect(collect($data)->every(fn($e) => $e['action'] === 'approve'))->toBeTrue();
    });

    it('filters audit events by actor_id', function () {
        makeAuditEvent(AuditAction::Login, $this->admin->id);
        makeAuditEvent(AuditAction::Login, $this->auditor->id);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/audit/events?filter[actor_id]=' . $this->admin->id);

        $response->assertStatus(200);
        $data = $response->json('data');
        expect(collect($data)->every(fn($e) => $e['actor_id'] === $this->admin->id))->toBeTrue();
    });

    it('filters audit events by date_from and date_to', function () {
        // Old event — created_at set at insertion time; no query-builder update needed
        makeAuditEvent(AuditAction::Login, $this->admin->id, '127.0.0.1', now()->subDays(10));

        // Recent event
        makeAuditEvent(AuditAction::Login, $this->admin->id);

        Sanctum::actingAs($this->admin);
        $from     = now()->subDays(2)->toIso8601String();
        $response = $this->getJson('/api/v1/audit/events?filter[date_from]=' . urlencode($from));

        $response->assertStatus(200);
        $data = $response->json('data');
        // Only the recent event should appear
        expect(count($data))->toBe(1);
    });

    // -------------------------------------------------------------------------
    // Show endpoint
    // -------------------------------------------------------------------------

    it('returns 200 with single event shape for admin', function () {
        $event = makeAuditEvent(AuditAction::SalesComplete, $this->admin->id);

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/audit/events/' . $event->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $event->id)
            ->assertJsonPath('data.action', 'sales_complete');
    });

    // -------------------------------------------------------------------------
    // Config promotions filtered view
    // -------------------------------------------------------------------------

    it('returns configuration promotion events from admin/config-promotions endpoint', function () {
        makeAuditEvent(AuditAction::RolloutStart, $this->admin->id);
        makeAuditEvent(AuditAction::RolloutPromote, $this->admin->id);
        makeAuditEvent(AuditAction::Login, $this->admin->id); // should be excluded

        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/config-promotions');

        $response->assertStatus(200);
        $data = $response->json('data');
        expect(collect($data)->every(fn($e) => in_array($e['action'], ['rollout_start', 'rollout_promote', 'rollout_back'])))->toBeTrue()
            ->and(count($data))->toBe(2);
    });

    it('returns 403 for non-admin/auditor on config-promotions', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/admin/config-promotions');

        $response->assertStatus(403);
    });
});
