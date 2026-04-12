<?php

use App\Models\Department;
use App\Models\InventoryMovement;
use App\Models\SalesDocument;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for explicit exchange endpoints.
 */
describe('Exchange Processing', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Exchange Ops', 'code' => 'EXO']);

        $this->manager = User::create([
            'username'      => 'exchange_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Exchange Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');
        $this->manager->givePermissionTo(['create sales', 'view sales', 'manage sales', 'void sales']);

        Sanctum::actingAs($this->manager);

        $createResponse = $this->postJson('/api/v1/sales', [
            'site_code'     => 'EX01',
            'department_id' => $this->dept->id,
            'notes'         => 'Base sale for exchange tests',
            'line_items'    => [
                [
                    'product_code' => 'EX-PROD-1',
                    'description'  => 'Exchange Product 1',
                    'quantity'     => 2,
                    'unit_price'   => 40.00,
                ],
            ],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $createResponse->assertStatus(201);

        $docId = $createResponse->json('data.id');
        $this->postJson(
            "/api/v1/sales/{$docId}/submit",
            [],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        )->assertStatus(200);
        $this->postJson(
            "/api/v1/sales/{$docId}/complete",
            [],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        )->assertStatus(200);

        $this->completedDoc = SalesDocument::findOrFail($docId);
    });

    it('creates an exchange through /sales/{document}/exchanges and marks operation_type as exchange', function () {
        Sanctum::actingAs($this->manager);

        $response = $this->postJson("/api/v1/sales/{$this->completedDoc->id}/exchanges", [
            'reason_code'   => 'wrong_item',
            'reason_detail' => 'Requesting exchange for size mismatch',
            'return_amount' => 80.00,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.operation_type', 'exchange')
                 ->assertJsonPath('data.status', 'pending');
    });

    it('lists only exchange records on /sales/{document}/exchanges', function () {
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/v1/sales/{$this->completedDoc->id}/returns", [
            'reason_code'   => 'changed_mind',
            'return_amount' => 80.00,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(201);

        $this->postJson("/api/v1/sales/{$this->completedDoc->id}/exchanges", [
            'reason_code'   => 'wrong_item',
            'return_amount' => 80.00,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(201);

        $response = $this->getJson("/api/v1/sales/{$this->completedDoc->id}/exchanges");

        $response->assertStatus(200);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.operation_type'))->toBe('exchange');
    });

    it('completes exchange through /exchanges/{return}/complete and records inventory rollback', function () {
        Sanctum::actingAs($this->manager);

        $exchangeResponse = $this->postJson("/api/v1/sales/{$this->completedDoc->id}/exchanges", [
            'reason_code'   => 'not_as_described',
            'return_amount' => 80.00,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $exchangeResponse->assertStatus(201);

        $exchangeId = $exchangeResponse->json('data.id');

        $response = $this->postJson(
            "/api/v1/exchanges/{$exchangeId}/complete",
            [],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.operation_type', 'exchange')
                 ->assertJsonPath('data.status', 'completed');

        $movementCount = InventoryMovement::where('return_id', $exchangeId)->count();
        expect($movementCount)->toBeGreaterThan(0);
    });
});
