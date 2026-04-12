<?php

use App\Domain\Sales\Enums\SalesStatus;
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
 * API tests for return processing — creation, window enforcement, and completion.
 */
describe('Return Processing', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Ecommerce', 'code' => 'ECO']);

        $this->manager = User::create([
            'username'      => 'return_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Return Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');
        $this->manager->givePermissionTo(['create sales', 'view sales', 'manage sales', 'void sales']);

        // Create and complete a base sales document for return tests
        Sanctum::actingAs($this->manager);

        $createResponse = $this->postJson('/api/v1/sales', [
            'site_code'     => 'ECO1',
            'department_id' => $this->dept->id,
            'notes'         => 'Base sale for returns',
            'line_items'    => [
                [
                    'product_code' => 'PROD-A',
                    'description'  => 'Product A',
                    'quantity'     => 3,
                    'unit_price'   => 30.00,
                ],
            ],
        ]);
        $createResponse->assertStatus(201);
        $docId = $createResponse->json('data.id');

        $this->postJson("/api/v1/sales/{$docId}/submit")->assertStatus(200);
        $this->postJson("/api/v1/sales/{$docId}/complete")->assertStatus(200);

        $this->completedDoc = SalesDocument::findOrFail($docId);
    });

    // -------------------------------------------------------------------------
    // Create return
    // -------------------------------------------------------------------------

    it('creates a return for a completed sale and returns 201 with pending status and restock fee', function () {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/sales/{$this->completedDoc->id}/returns", [
            'reason_code'   => 'changed_mind',
            'reason_detail' => 'Item did not meet expectations.',
            'return_amount' => 90.00,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.status', 'pending');

        // Default 10% restock fee
        expect($response->json('data.restock_fee_amount'))->toBe(9.0);
        expect($response->json('data.refund_amount'))->toBe(81.0);
    });

    it('creates a return with 0 restock fee for defective items', function () {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/sales/{$this->completedDoc->id}/returns", [
            'reason_code'   => 'defective',
            'reason_detail' => 'Product arrived broken.',
            'return_amount' => 90.00,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.restock_fee_amount', 0.0)
                 ->assertJsonPath('data.refund_amount', 90.0);
    });

    it('returns 422 with return_window_expired for non-defective return beyond 30 days', function () {
        Sanctum::actingAs($this->manager);

        // Backdate completed_at to 31 days ago to simulate expired window
        $this->completedDoc->update(['completed_at' => now()->subDays(31)]);

        $response = $this->postJson("/api/v1/sales/{$this->completedDoc->id}/returns", [
            'reason_code'   => 'changed_mind',
            'return_amount' => 90.00,
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'return_window_expired');
    });

    it('returns 409 with invalid_sales_transition when creating a return for a non-completed document', function () {
        Sanctum::actingAs($this->manager);

        // Create a fresh draft document
        $draftResponse = $this->postJson('/api/v1/sales', [
            'site_code'     => 'ECO1',
            'department_id' => $this->dept->id,
        ]);
        $draftId = $draftResponse->json('data.id');

        $response = $this->postJson("/api/v1/sales/{$draftId}/returns", [
            'reason_code'   => 'changed_mind',
            'return_amount' => 50.00,
        ]);

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'invalid_sales_transition');
    });

    it('returns 403 when a same-department user lacks manage sales permission for return creation', function () {
        $staff = User::create([
            'username'      => 'return_staff_only',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Return Staff Only',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $staff->assignRole('staff'); // has view sales but not manage sales

        Sanctum::actingAs($staff);

        $response = $this->postJson("/api/v1/sales/{$this->completedDoc->id}/returns", [
            'reason_code'   => 'changed_mind',
            'return_amount' => 50.00,
        ]);

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // Complete return
    // -------------------------------------------------------------------------

    it('completes a return and returns 200 with compensating inventory movements', function () {
        Sanctum::actingAs($this->manager);

        // Create the return
        $returnResponse = $this->postJson("/api/v1/sales/{$this->completedDoc->id}/returns", [
            'reason_code'   => 'wrong_item',
            'return_amount' => 90.00,
        ]);
        $returnResponse->assertStatus(201);
        $returnId = $returnResponse->json('data.id');

        // Complete the return
        $response = $this->postJson("/api/v1/returns/{$returnId}/complete");

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'completed');

        // Compensating stock-in inventory movements should exist
        $movements = InventoryMovement::where('return_id', $returnId)->get();
        expect($movements->count())->toBeGreaterThan(0);
        expect($movements->first()->quantity_delta)->toBeGreaterThan(0);

        // Each compensating movement must have a corresponding audit event (compliance requirement).
        $movementIds = $movements->pluck('id');
        $auditCount  = AuditEvent::whereIn('auditable_id', $movementIds)->count();
        expect($auditCount)->toBe($movementIds->count());
    });

    // -------------------------------------------------------------------------
    // Cross-department authorization (object-level scope)
    // -------------------------------------------------------------------------

    it('returns 403 when a user from a different department tries to update a return', function () {
        Sanctum::actingAs($this->manager);

        $returnResponse = $this->postJson("/api/v1/sales/{$this->completedDoc->id}/returns", [
            'reason_code'   => 'changed_mind',
            'return_amount' => 50.00,
        ]);
        $returnResponse->assertStatus(201);
        $returnId = $returnResponse->json('data.id');

        // Outsider from a different department with manage sales permission
        $otherDept = Department::create(['name' => 'Logistics', 'code' => 'LOG']);
        $outsider = User::create([
            'username'      => 'return_outsider_upd',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'Return Outsider',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $outsider->assignRole('staff');
        $outsider->givePermissionTo('manage sales');

        Sanctum::actingAs($outsider);

        $response = $this->putJson("/api/v1/returns/{$returnId}", [
            'reason_detail' => 'Attempted unauthorized update',
        ]);

        // Has manage sales permission but wrong department → 403
        $response->assertStatus(403);
    });

    it('returns 403 when a user from a different department tries to complete a return', function () {
        Sanctum::actingAs($this->manager);

        $returnResponse = $this->postJson("/api/v1/sales/{$this->completedDoc->id}/returns", [
            'reason_code'   => 'defective',
            'is_defective'  => true,
            'return_amount' => 75.00,
        ]);
        $returnResponse->assertStatus(201);
        $returnId = $returnResponse->json('data.id');

        // Outsider from a different department with manage sales permission
        $otherDept = Department::create(['name' => 'Finance', 'code' => 'FIN']);
        $outsider = User::create([
            'username'      => 'return_outsider_cmp',
            'password_hash' => \Illuminate\Support\Facades\Hash::make('ValidPass1!'),
            'display_name'  => 'Return Outsider Complete',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $outsider->assignRole('staff');
        $outsider->givePermissionTo('manage sales');

        Sanctum::actingAs($outsider);

        $response = $this->postJson("/api/v1/returns/{$returnId}/complete");

        // Has manage sales permission but wrong department → 403
        $response->assertStatus(403);
    });
});
