<?php

use App\Domain\Configuration\Enums\RolloutStatus;
use App\Models\ConfigurationSet;
use App\Models\ConfigurationVersion;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for Configuration Center — sets, versions, and canary rollout lifecycle.
 */
describe('Configuration Version Lifecycle', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Operations', 'code' => 'OPS']);

        $this->manager = User::create([
            'username'      => 'config_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Config Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');
        $this->manager->givePermissionTo('manage configuration');
        $this->manager->givePermissionTo('manage rollouts');
        $this->manager->givePermissionTo('view configuration');

        // Configure server-authoritative eligible store IDs for store-target rollout validation.
        $this->storeIds = array_map(fn() => Str::uuid()->toString(), range(1, 100));
        Config::set('meridian.canary.store_ids', $this->storeIds);
        Config::set('meridian.canary.store_count', count($this->storeIds));

        // Create 20 additional active users so user-type canary cap tests are deterministic.
        // Total active users = 21 (manager + 20 below) → maxTargets = floor(21 * 0.10) = 2.
        for ($i = 1; $i <= 20; $i++) {
            User::create([
                'username'      => "canary_user_{$i}",
                'password_hash' => Hash::make('ValidPass1!'),
                'display_name'  => "Canary User {$i}",
                'department_id' => $this->dept->id,
                'is_active'     => true,
            ]);
        }
    });

    // -------------------------------------------------------------------------
    // Configuration Set
    // -------------------------------------------------------------------------

    it('creates a configuration set and returns 201', function () {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson('/api/v1/configuration/sets', [
            'name'          => 'Pricing Rules 2025',
            'description'   => 'All pricing-related configuration',
            'department_id' => $this->dept->id,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.name', 'Pricing Rules 2025')
                 ->assertJsonPath('data.is_active', true);
    });

    // -------------------------------------------------------------------------
    // Configuration Version
    // -------------------------------------------------------------------------

    it('creates a configuration version with rules and returns 201', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'          => 'Coupon Config',
            'description'   => null,
            'department_id' => $this->dept->id,
            'created_by'    => $this->manager->id,
            'is_active'     => true,
        ]);

        $response = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload'        => ['max_discount' => 50],
            'change_summary' => 'Initial version',
            'rules'          => [
                [
                    'rule_type'   => 'coupon',
                    'rule_key'    => 'SUMMER10',
                    'rule_value'  => ['discount' => 10],
                    'is_active'   => true,
                    'priority'    => 1,
                    'description' => 'Summer coupon 10%',
                ],
            ],
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.version_number', 1)
                 ->assertJsonPath('data.status', RolloutStatus::Draft->value);
    });

    it('increments version_number on subsequent versions', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'          => 'Ad Slots',
            'description'   => null,
            'department_id' => $this->dept->id,
            'created_by'    => $this->manager->id,
            'is_active'     => true,
        ]);

        $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['slot' => 'home_hero'],
        ])->assertStatus(201);

        $response = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['slot' => 'home_hero_v2'],
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.version_number', 2);
    });

    // -------------------------------------------------------------------------
    // Canary Rollout
    // -------------------------------------------------------------------------

    it('starts canary rollout and returns 200 with canary status', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'       => 'Promo Config',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['promo' => 'SUMMER'],
        ]);
        $versionId = $versionResponse->json('data.id');

        // 5 targets out of 100 configured eligible stores → 5% → within 10% cap
        $targetIds = array_slice($this->storeIds, 0, 5);

        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type' => 'store',
            'target_ids'  => $targetIds,
            // eligible_count is NOT sent — computed server-side
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', RolloutStatus::Canary->value);
    });

    it('returns 422 with canary_cap_exceeded when target count exceeds 10%', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'       => 'Over-Cap Config',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['key' => 'value'],
        ]);
        $versionId = $versionResponse->json('data.id');

        // 20 user targets out of ~21 active users → ~95% → far exceeds 10% cap
        // floor(21 * 0.10) = 2, so 20 > 2 triggers cap exceeded
        $targetIds = User::query()->limit(20)->pluck('id')->all();

        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type' => 'user',
            'target_ids'  => $targetIds,
            // eligible_count is NOT sent — computed server-side from active user count
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'canary_cap_exceeded');
    });


    it('returns 409 with canary_store_count_misconfigured when store rollout denominator is zero', function () {
        Sanctum::actingAs($this->manager);
        Config::set('meridian.canary.store_count', 0);
        Config::set('meridian.canary.store_ids', []);

        $set = ConfigurationSet::create([
            'name'       => 'Store Misconfig Config',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['key' => 'store-rollout'],
        ]);
        $versionId = $versionResponse->json('data.id');

        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type' => 'store',
            'target_ids'  => [Str::uuid()->toString()],
        ]);

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'canary_store_count_misconfigured');
    });

    it('returns 422 when store target_ids are outside configured eligible store IDs', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'       => 'Store Eligibility Guard',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['key' => 'eligibility-guard'],
        ]);
        $versionId = $versionResponse->json('data.id');

        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type' => 'store',
            'target_ids'  => [Str::uuid()->toString()],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'validation_error')
                 ->assertJsonStructure(['error' => ['details' => ['target_ids']]]);
    });

    it('ignores client-supplied eligible_count and enforces server-side cap', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'       => 'Tamper Canary Config',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['key' => 'tamper'],
        ]);
        $versionId = $versionResponse->json('data.id');

        // Send 20 valid active user targets. Client tries to inflate eligible_count to 999 to bypass cap.
        // Server ignores eligible_count and uses DB count (~21) → 20 > floor(21 * 0.1) = 2 → 422.
        $targetIds = User::query()->limit(20)->pluck('id')->all();

        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type'    => 'user',
            'target_ids'     => $targetIds,
            'eligible_count' => 999, // client-supplied; server must ignore this
        ]);

        // Despite inflated client-provided eligible_count, server cap is still enforced → 422
        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'canary_cap_exceeded');
    });

    it('succeeds with 200 when store_count is configured and rollout is within cap', function () {
        Sanctum::actingAs($this->manager);
        Config::set('meridian.canary.store_ids', array_slice($this->storeIds, 0, 10));
        Config::set('meridian.canary.store_count', 10);

        $set = ConfigurationSet::create([
            'name'       => 'Store Canary Positive',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['key' => 'store-positive'],
        ]);
        $versionResponse->assertStatus(201);
        $versionId = $versionResponse->json('data.id');

        // 1 target out of 10 configured stores = 10% → exactly at cap boundary → allowed
        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type' => 'store',
            'target_ids'  => [Config::get('meridian.canary.store_ids')[0]],
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', RolloutStatus::Canary->value);
    });

    it('returns 409 with canary_not_ready when attempting promotion before 24h', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'       => 'Promo Canary Early',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['x' => 1],
        ]);
        $versionId = $versionResponse->json('data.id');

        // Start canary with 1 store target out of server-side store_count=100 → 1% → within cap
        $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type' => 'store',
            'target_ids'  => [$this->storeIds[0]],
            // eligible_count NOT sent — computed server-side
        ])->assertStatus(200);

        // Attempt immediate promotion (within 24h)
        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/promote");

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'canary_not_ready');
    });

    it('promotes version after mocking 24h elapsed and returns 200 with promoted status', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'       => 'Promo Canary Ready',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['y' => 2],
        ]);
        $versionId = $versionResponse->json('data.id');

        // Start canary with 1 store target out of server-side store_count=100 → 1% → within cap
        $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type' => 'store',
            'target_ids'  => [$this->storeIds[1]],
            // eligible_count NOT sent — computed server-side
        ])->assertStatus(200);

        // Manually backdate canary_started_at to 25 hours ago so the window passes
        ConfigurationVersion::where('id', $versionId)->update([
            'canary_started_at' => now()->subHours(25),
        ]);

        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/promote");

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', RolloutStatus::Promoted->value);
    });

    it('rolls back a canary version and returns 200 with rolled_back status', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'       => 'Rollback Config',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['z' => 3],
        ]);
        $versionId = $versionResponse->json('data.id');

        // Start canary with 1 store target (server-side store_count=100 → 1% → within cap)
        $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type' => 'store',
            'target_ids'  => [$this->storeIds[2]],
            // eligible_count NOT sent — computed server-side
        ])->assertStatus(200);

        // Roll back
        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/rollback");

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', RolloutStatus::RolledBack->value);
    });

    it('returns 409 with invalid_rollout_transition when rolling back a draft version', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'       => 'Draft Rollback Attempt',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['a' => 1],
        ]);
        $versionId = $versionResponse->json('data.id');

        // Draft → rollback is not a valid transition
        $response = $this->postJson("/api/v1/configuration/versions/{$versionId}/rollback");

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'invalid_rollout_transition');
    });

    it('returns 403 when rollback is attempted without manage rollouts permission', function () {
        Sanctum::actingAs($this->manager);

        $set = ConfigurationSet::create([
            'name'       => 'Rollback Auth Guard',
            'created_by' => $this->manager->id,
            'is_active'  => true,
        ]);

        $versionResponse = $this->postJson("/api/v1/configuration/sets/{$set->id}/versions", [
            'payload' => ['guard' => true],
        ]);
        $versionId = $versionResponse->json('data.id');

        $this->postJson("/api/v1/configuration/versions/{$versionId}/rollout", [
            'target_type' => 'store',
            'target_ids'  => [$this->storeIds[3]],
        ])->assertStatus(200);

        $limitedUser = User::create([
            'username'      => 'config_no_rollout_perm',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Config No Rollout Perm',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $limitedUser->assignRole('staff');
        $limitedUser->givePermissionTo(['manage configuration', 'view configuration']);

        Sanctum::actingAs($limitedUser);

        $response = $this->postJson(
            "/api/v1/configuration/versions/{$versionId}/rollback",
            [],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // Object-level isolation: foreign-department access must be denied
    // -------------------------------------------------------------------------

    it('returns 403 when a user views a configuration set from a different department', function () {
        // Create a set belonging to the manager's department
        $set = ConfigurationSet::create([
            'name'          => 'Finance Set',
            'department_id' => $this->dept->id,
            'created_by'    => $this->manager->id,
            'is_active'     => true,
        ]);

        // Create a user in a different department with view configuration permission
        $foreignDept = Department::create(['name' => 'HR', 'code' => 'HR1']);
        $foreignUser = User::create([
            'username'      => 'config_foreign_viewer',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Foreign Config Viewer',
            'department_id' => $foreignDept->id,
            'is_active'     => true,
        ]);
        $foreignUser->assignRole('staff');
        $foreignUser->givePermissionTo('view configuration');

        Sanctum::actingAs($foreignUser);

        $response = $this->getJson("/api/v1/configuration/sets/{$set->id}");

        // Non-admin/manager user from a different department must be denied
        $response->assertStatus(403);
    });

    it('returns 403 when a user updates a configuration set from a different department', function () {
        $set = ConfigurationSet::create([
            'name'          => 'Finance Update Set',
            'department_id' => $this->dept->id,
            'created_by'    => $this->manager->id,
            'is_active'     => true,
        ]);

        $foreignDept = Department::create(['name' => 'Legal', 'code' => 'LGL']);
        $foreignUser = User::create([
            'username'      => 'config_foreign_editor',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Foreign Config Editor',
            'department_id' => $foreignDept->id,
            'is_active'     => true,
        ]);
        $foreignUser->assignRole('staff');
        $foreignUser->givePermissionTo('manage configuration');

        Sanctum::actingAs($foreignUser);

        $response = $this->putJson("/api/v1/configuration/sets/{$set->id}", [
            'name' => 'Unauthorized Rename',
        ]);

        $response->assertStatus(403);
    });

    it('allows a user to view a system-wide (null department) configuration set', function () {
        // System-wide sets (department_id = null) are accessible to any user with view permission
        $systemSet = ConfigurationSet::create([
            'name'          => 'Global Platform Rules',
            'department_id' => null, // system-wide
            'created_by'    => $this->manager->id,
            'is_active'     => true,
        ]);

        $otherDept = Department::create(['name' => 'Marketing', 'code' => 'MKT']);
        $otherUser = User::create([
            'username'      => 'config_other_viewer',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Other Dept Viewer',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $otherUser->assignRole('staff');
        $otherUser->givePermissionTo('view configuration');

        Sanctum::actingAs($otherUser);

        $response = $this->getJson("/api/v1/configuration/sets/{$systemSet->id}");

        // System-wide sets must be accessible to any permitted user
        $response->assertStatus(200);
    });

});
