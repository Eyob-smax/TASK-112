<?php

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\SalesDocument;
use App\Models\SalesLineItem;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests covering SalesDocumentController::update + UpdateSalesDocumentRequest +
 * SalesDocumentService::update — none of which were exercised in the baseline.
 */
describe('Sales Document Update', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Outlet', 'code' => 'OUT']);

        $this->manager = User::create([
            'username'      => 'sd_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'SD Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');
        $this->manager->givePermissionTo(['create sales', 'view sales', 'manage sales']);
    });

    it('update replaces line items, recomputes total, and records an Update audit event', function () {
        Sanctum::actingAs($this->manager);

        // Create a draft with 1 line
        $created = $this->postJson('/api/v1/sales', [
            'site_code'     => 'OUT1',
            'department_id' => $this->dept->id,
            'line_items'    => [['product_code' => 'P1', 'description' => 'P1', 'quantity' => 1, 'unit_price' => 10]],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $created->assertStatus(201);
        $docId = $created->json('data.id');

        // PUT with two new lines and updated notes
        $response = $this->putJson("/api/v1/sales/{$docId}", [
            'notes'      => 'Replaced lines',
            'line_items' => [
                ['product_code' => 'NEW1', 'description' => 'New 1', 'quantity' => 2, 'unit_price' => 15.00],
                ['product_code' => 'NEW2', 'description' => 'New 2', 'quantity' => 1, 'unit_price' => 25.00],
            ],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.notes', 'Replaced lines');

        $doc = SalesDocument::with('lineItems')->find($docId);
        expect($doc->total_amount)->toEqual(55.00);
        expect($doc->lineItems->pluck('product_code')->all())->toBe(['NEW1', 'NEW2']);

        expect(AuditEvent::where('auditable_id', $docId)
            ->where('action', 'update')->exists())->toBeTrue();
    });

    it('update returns 422 when a line item is missing product_code', function () {
        Sanctum::actingAs($this->manager);

        $created = $this->postJson('/api/v1/sales', [
            'site_code'     => 'OUT1',
            'department_id' => $this->dept->id,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $docId = $created->json('data.id');

        $response = $this->putJson("/api/v1/sales/{$docId}", [
            'line_items' => [
                ['description' => 'no code', 'quantity' => 1, 'unit_price' => 10],
            ],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'validation_error');

        $details = $response->json('error.details');
        expect(is_array($details))->toBeTrue();
        expect(array_key_exists('line_items.0.product_code', $details))->toBeTrue();
    });

    it('update returns 422 when quantity is below minimum', function () {
        Sanctum::actingAs($this->manager);

        $created = $this->postJson('/api/v1/sales', [
            'site_code'     => 'OUT1',
            'department_id' => $this->dept->id,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $docId = $created->json('data.id');

        $response = $this->putJson("/api/v1/sales/{$docId}", [
            'line_items' => [
                ['product_code' => 'X', 'quantity' => 0, 'unit_price' => 5],
            ],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'validation_error');

        $details = $response->json('error.details');
        expect(is_array($details))->toBeTrue();
        expect(array_key_exists('line_items.0.quantity', $details))->toBeTrue();
    });

    it('update updates only notes when line_items omitted', function () {
        Sanctum::actingAs($this->manager);

        $created = $this->postJson('/api/v1/sales', [
            'site_code'     => 'OUT1',
            'department_id' => $this->dept->id,
            'line_items'    => [['product_code' => 'P1', 'description' => 'P1 desc', 'quantity' => 2, 'unit_price' => 10]],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $docId = $created->json('data.id');

        $beforeLines = SalesLineItem::where('sales_document_id', $docId)->count();

        $response = $this->putJson("/api/v1/sales/{$docId}", [
            'notes' => 'Only notes changed',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.notes', 'Only notes changed');

        // Line items untouched
        expect(SalesLineItem::where('sales_document_id', $docId)->count())->toBe($beforeLines);
    });

    it('update returns 409 invalid_sales_transition when document is not in editable status', function () {
        Sanctum::actingAs($this->manager);

        // Create + submit so it leaves Draft (Reviewed status is non-editable)
        $created = $this->postJson('/api/v1/sales', [
            'site_code'     => 'OUT1',
            'department_id' => $this->dept->id,
            'line_items'    => [['product_code' => 'P1', 'description' => 'P1 desc', 'quantity' => 1, 'unit_price' => 10]],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $docId = $created->json('data.id');
        $this->postJson("/api/v1/sales/{$docId}/submit", [], ['X-Idempotency-Key' => Str::uuid()->toString()])->assertStatus(200);

        $response = $this->putJson("/api/v1/sales/{$docId}", [
            'notes' => 'attempt while reviewed',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'invalid_sales_transition');
    });

    it('patch updates notes on a draft sales document and returns 200', function () {
        Sanctum::actingAs($this->manager);

        $created = $this->postJson('/api/v1/sales', [
            'site_code'     => 'OUT1',
            'department_id' => $this->dept->id,
            'line_items'    => [['product_code' => 'P1', 'description' => 'P1', 'quantity' => 1, 'unit_price' => 10]],
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);
        $created->assertStatus(201);
        $docId = $created->json('data.id');

        $response = $this->patchJson("/api/v1/sales/{$docId}", [
            'notes' => 'Patched draft note',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.notes', 'Patched draft note');
    });
});
