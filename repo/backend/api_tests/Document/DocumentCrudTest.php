<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for document CRUD operations.
 *
 * Tests cover creation, retrieval, update, archive state machine,
 * department-scope isolation, and authorization enforcement.
 */
describe('Document CRUD', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Operations', 'code' => 'OPS']);

        $this->manager = User::create([
            'username'      => 'doc_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Document Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');

        $this->staff = User::create([
            'username'      => 'doc_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Document Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');

        $otherDept = Department::create(['name' => 'HR', 'code' => 'HR1']);

        $this->outsider = User::create([
            'username'      => 'doc_outsider',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Outsider',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $this->outsider->assignRole('staff');
    });

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    it('creates a document and returns 201 with draft status', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $response = $this->postJson('/api/v1/documents', [
            'title'                => 'Procurement Policy',
            'document_type'        => 'policy',
            'description'          => 'Governs all procurement activities.',
            'access_control_scope' => 'department',
            'department_id'        => $this->dept->id,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Procurement Policy')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.is_archived', false)
            ->assertJsonPath('data.owner_id', $this->manager->id);
    });

    it('returns 403 when a viewer tries to create a document', function () {
        $viewer = User::create([
            'username'      => 'viewer_usr',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Viewer',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $viewer->assignRole('viewer');
        Sanctum::actingAs($viewer, ['*'], 'sanctum');

        $response = $this->postJson('/api/v1/documents', [
            'title'                => 'Test',
            'document_type'        => 'policy',
            'access_control_scope' => 'department',
            'department_id'        => $this->dept->id,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403);
    });

    it('returns 403 when creating a document in a different department', function () {
        Sanctum::actingAs($this->outsider, ['*'], 'sanctum');

        $response = $this->postJson('/api/v1/documents', [
            'title'                => 'Cross Department Policy',
            'document_type'        => 'policy',
            'access_control_scope' => 'department',
            'department_id'        => $this->dept->id,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    });

    // -------------------------------------------------------------------------
    // Show
    // -------------------------------------------------------------------------

    it('returns 200 with document data on GET', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        $doc = Document::create([
            'title'                => 'HR Guidelines',
            'document_type'        => 'form',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->staff->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);

        $response = $this->getJson("/api/v1/documents/{$doc->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $doc->id)
            ->assertJsonPath('data.title', 'HR Guidelines');
    });

    it('returns 403 when staff from different department views a department-scoped document', function () {
        Sanctum::actingAs($this->outsider, ['*'], 'sanctum');

        $doc = Document::create([
            'title'                => 'Internal Policy',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);

        $response = $this->getJson("/api/v1/documents/{$doc->id}");

        $response->assertStatus(403);
    });

    it('keeps document description intact and does not inject masked notes fields on show/list', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        $doc = Document::create([
            'title'                => 'Masking Scope Document',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'description'          => 'Document description must remain visible.',
            'is_archived'          => false,
        ]);

        $showResponse = $this->getJson("/api/v1/documents/{$doc->id}");

        $showResponse->assertStatus(200)
            ->assertJsonPath('data.description', 'Document description must remain visible.')
            ->assertJsonMissingPath('data.notes');

        expect($showResponse->getContent())->not->toContain('[REDACTED]');

        $listResponse = $this->getJson('/api/v1/documents');

        $listResponse->assertStatus(200)
            ->assertJsonPath('data.0.description', 'Document description must remain visible.')
            ->assertJsonMissingPath('data.0.notes');

        expect($listResponse->getContent())->not->toContain('[REDACTED]');
    });

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    it('updates document title and returns 200', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $doc = Document::create([
            'title'                => 'Old Title',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);

        $response = $this->putJson("/api/v1/documents/{$doc->id}", [
            'title' => 'New Title',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'New Title');
    });

    it('returns 409 when updating an archived document', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $doc = Document::create([
            'title'                => 'Frozen Policy',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'archived',
            'access_control_scope' => 'department',
            'is_archived'          => true,
            'archived_at'          => now(),
            'archived_by'          => $this->manager->id,
        ]);

        $response = $this->putJson("/api/v1/documents/{$doc->id}", [
            'title' => 'Attempted Update',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'document_archived');
    });

    // -------------------------------------------------------------------------
    // Archive
    // -------------------------------------------------------------------------

    it('archives a document and returns 200 with is_archived=true', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $doc = Document::create([
            'title'                => 'Policy to Archive',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'published',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);

        $response = $this->postJson("/api/v1/documents/{$doc->id}/archive", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_archived', true)
            ->assertJsonPath('data.status', 'archived');
    });

    it('returns 409 when archiving an already-archived document', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $doc = Document::create([
            'title'                => 'Already Frozen',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'archived',
            'access_control_scope' => 'department',
            'is_archived'          => true,
            'archived_at'          => now()->subDay(),
            'archived_by'          => $this->manager->id,
        ]);

        $response = $this->postJson("/api/v1/documents/{$doc->id}/archive", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'document_archived');
    });

    it('returns 403 when a user from a different department tries to archive a document', function () {
        // $this->outsider belongs to a different department than the document
        Sanctum::actingAs($this->outsider, ['*'], 'sanctum');
        $this->outsider->givePermissionTo('archive documents');

        $doc = Document::create([
            'title'                => 'Cross-Dept Archive Target',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'published',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);

        $response = $this->postJson("/api/v1/documents/{$doc->id}/archive", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        // Outsider has the permission but wrong department → object-level 403
        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    it('lists documents paginated and returns 200', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        Document::create([
            'title'                => 'Doc A',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->staff->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);

        $response = $this->getJson('/api/v1/documents');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']],
            ]);
    });

});
