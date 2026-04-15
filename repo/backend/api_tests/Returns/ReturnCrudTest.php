<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\SalesDocument;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests covering the index/show/update/indexExchanges/completeExchange endpoints
 * of ReturnController. The baseline exercised only create/complete of regular returns;
 * these tests fill the gap across all read + exchange paths.
 */
describe('Return CRUD', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Store',  'code' => 'STR']);

        $this->manager = User::create([
            'username'      => 'ret_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Ret Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');
        $this->manager->givePermissionTo(['create sales', 'view sales', 'manage sales']);

        Sanctum::actingAs($this->manager);

        // Create + submit + complete a base doc so returns can be created against it
        $created = $this->postJson('/api/v1/sales', [
            'site_code'     => 'STR1',
            'department_id' => $this->dept->id,
            'line_items'    => [[
                'product_code' => 'X',
                'description'  => 'X item',
                'quantity'     => 2,
                'unit_price'   => 40,
            ]],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $created->assertStatus(201);
        $this->docId = $created->json('data.id');

        $this->postJson("/api/v1/sales/{$this->docId}/submit", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);
        $this->postJson("/api/v1/sales/{$this->docId}/complete", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);

        $this->doc = SalesDocument::findOrFail($this->docId);
    });

    // -------------------------------------------------------------------------
    // index (per-document)
    // -------------------------------------------------------------------------

    it('index returns all returns (exchange or not) for a sales document', function () {
        Sanctum::actingAs($this->manager);

        // Create a plain return
        $this->postJson("/api/v1/sales/{$this->docId}/returns", [
            'reason_code'   => 'changed_mind',
            'return_amount' => 50.0,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(201);

        // Create an exchange
        $this->postJson("/api/v1/sales/{$this->docId}/exchanges", [
            'reason_code'   => 'wrong_item',
            'return_amount' => 30.0,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(201);

        $response = $this->getJson("/api/v1/sales/{$this->docId}/returns");
        $response->assertStatus(200)
                 ->assertJsonCount(2, 'data');
    });

    // -------------------------------------------------------------------------
    // indexExchanges (exchange-only scope)
    // -------------------------------------------------------------------------

    it('indexExchanges returns only records with operation_type=exchange', function () {
        Sanctum::actingAs($this->manager);

        $this->postJson("/api/v1/sales/{$this->docId}/returns", [
            'reason_code'   => 'changed_mind',
            'return_amount' => 20.0,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(201);

        $this->postJson("/api/v1/sales/{$this->docId}/exchanges", [
            'reason_code'   => 'wrong_item',
            'return_amount' => 30.0,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(201);

        $response = $this->getJson("/api/v1/sales/{$this->docId}/exchanges");
        $response->assertStatus(200)
                 ->assertJsonCount(1, 'data')
                 ->assertJsonPath('data.0.operation_type', 'exchange');
    });

    // -------------------------------------------------------------------------
    // show
    // -------------------------------------------------------------------------

    it('show returns the return record with embedded sales_document', function () {
        Sanctum::actingAs($this->manager);

        $created = $this->postJson("/api/v1/sales/{$this->docId}/returns", [
            'reason_code'   => 'defective',
            'return_amount' => 40.0,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $created->assertStatus(201);
        $returnId = $created->json('data.id');

        $response = $this->getJson("/api/v1/returns/{$returnId}");
        $response->assertStatus(200)
                 ->assertJsonPath('data.id', $returnId)
                 ->assertJsonStructure(['data' => ['sales_document' => ['id', 'document_number']]]);
    });

    // -------------------------------------------------------------------------
    // update
    // -------------------------------------------------------------------------

    it('update patches reason_detail and records an Update audit event', function () {
        Sanctum::actingAs($this->manager);

        $created = $this->postJson("/api/v1/sales/{$this->docId}/returns", [
            'reason_code'   => 'not_as_described',
            'return_amount' => 40.0,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $returnId = $created->json('data.id');

        $response = $this->putJson("/api/v1/returns/{$returnId}", [
            'reason_detail' => 'Customer confirmed item did not match photos on file.',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.reason_detail', 'Customer confirmed item did not match photos on file.');

        expect(AuditEvent::where('auditable_id', $returnId)
            ->where('action', 'update')->exists())->toBeTrue();
    });

    // -------------------------------------------------------------------------
    // completeExchange — 409 guard when the record is NOT an exchange
    // -------------------------------------------------------------------------

    it('completeExchange returns 409 when the target record is a plain return (not an exchange)', function () {
        Sanctum::actingAs($this->manager);

        $created = $this->postJson("/api/v1/sales/{$this->docId}/returns", [
            'reason_code'   => 'changed_mind',
            'return_amount' => 30.0,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $returnId = $created->json('data.id');

        $response = $this->postJson("/api/v1/exchanges/{$returnId}/complete", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $response->assertStatus(409);
    });

    it('completeExchange returns 200 and completes an actual exchange record', function () {
        Sanctum::actingAs($this->manager);

        $created = $this->postJson("/api/v1/sales/{$this->docId}/exchanges", [
            'reason_code'   => 'wrong_item',
            'return_amount' => 30.0,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $created->assertStatus(201);
        $exchangeId = $created->json('data.id');

        $response = $this->postJson("/api/v1/exchanges/{$exchangeId}/complete", [], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'completed')
                 ->assertJsonPath('data.operation_type', 'exchange');
    });
});
