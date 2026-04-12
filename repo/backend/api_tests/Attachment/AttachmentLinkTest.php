<?php

use App\Models\Attachment;
use App\Models\AttachmentLink;
use App\Models\Department;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for LAN share link creation, resolution, and lifecycle enforcement.
 */
describe('Attachment Share Links', function () {

    beforeEach(function () {
        Storage::fake('local');
        putenv('ATTACHMENT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));

        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Finance', 'code' => 'FIN2']);

        $this->manager = User::create([
            'username'      => 'link_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Link Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');

        $this->doc = Document::create([
            'title'                => 'Finance Report',
            'document_type'        => 'report',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);

        // Upload one attachment to work with
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $file = UploadedFile::fake()->create('financial_report.pdf', 200, 'application/pdf');

        $upload = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $this->attachmentId = $upload->json('data.0.id');
        $this->attachment   = Attachment::find($this->attachmentId);
    });

    // -------------------------------------------------------------------------
    // Link creation
    // -------------------------------------------------------------------------

    it('creates a share link and returns 201 with a URL', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $response = $this->postJson(
            "/api/v1/attachments/{$this->attachmentId}/links",
            ['ttl_hours' => 24, 'is_single_use' => false],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.attachment_id', $this->attachmentId)
            ->assertJsonPath('data.is_single_use', false)
            ->assertJsonStructure(['data' => ['id', 'url', 'expires_at']]);

        // URL must contain the API path
        $url = $response->json('data.url');
        expect($url)->toContain('/api/v1/links/');
    });

    // -------------------------------------------------------------------------
    // Link resolution
    // -------------------------------------------------------------------------

    it('resolves a link via GET /links/{token} and returns file content with 200', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $linkResponse = $this->postJson(
            "/api/v1/attachments/{$this->attachmentId}/links",
            ['ttl_hours' => 1, 'is_single_use' => false],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $link    = AttachmentLink::where('attachment_id', $this->attachmentId)->first();
        $token   = $link->token;

        // Resolve without Bearer token — public endpoint
        $response = $this->get("/api/v1/links/{$token}");

        $response->assertStatus(200);
        expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    });

    // -------------------------------------------------------------------------
    // Link expiry
    // -------------------------------------------------------------------------

    it('returns 410 with link_expired when resolving an expired link', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->postJson(
            "/api/v1/attachments/{$this->attachmentId}/links",
            ['ttl_hours' => 1, 'is_single_use' => false],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $link = AttachmentLink::where('attachment_id', $this->attachmentId)->first();

        // Manually expire the link
        $link->update(['expires_at' => now()->subHour()]);

        $response = $this->get("/api/v1/links/{$link->token}");

        $response->assertStatus(410)
            ->assertJsonPath('error.code', 'link_expired');
    });

    // -------------------------------------------------------------------------
    // Link revocation
    // -------------------------------------------------------------------------

    it('returns 410 with link_revoked when resolving a revoked link', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->postJson(
            "/api/v1/attachments/{$this->attachmentId}/links",
            ['ttl_hours' => 24, 'is_single_use' => false],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $link = AttachmentLink::where('attachment_id', $this->attachmentId)->first();

        // Manually revoke the link
        $link->update(['revoked_at' => now(), 'revoked_by' => $this->manager->id]);

        $response = $this->get("/api/v1/links/{$link->token}");

        $response->assertStatus(410)
            ->assertJsonPath('error.code', 'link_revoked');
    });

    // -------------------------------------------------------------------------
    // Single-use consumption
    // -------------------------------------------------------------------------

    it('returns 410 with link_consumed on second resolution of a single-use link', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->postJson(
            "/api/v1/attachments/{$this->attachmentId}/links",
            ['ttl_hours' => 24, 'is_single_use' => true],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $link = AttachmentLink::where('attachment_id', $this->attachmentId)->first();

        // First resolution — should succeed and consume the link
        $this->get("/api/v1/links/{$link->token}")->assertStatus(200);

        $link->refresh();
        expect($link->consumed_at)->not->toBeNull();
        expect($link->consumed_by)->toBeNull();

        // Second resolution — link is consumed
        $response = $this->get("/api/v1/links/{$link->token}");

        $response->assertStatus(410)
            ->assertJsonPath('error.code', 'link_consumed');
    });

    it('ensures only one consumer wins when two requests race to consume a single-use link', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->postJson(
            "/api/v1/attachments/{$this->attachmentId}/links",
            ['ttl_hours' => 24, 'is_single_use' => true],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $link = AttachmentLink::where('attachment_id', $this->attachmentId)->first();

        // First consume — must succeed
        $first = $this->get("/api/v1/links/{$link->token}");
        $first->assertStatus(200);

        // Second consume (simulating a racing request that arrives after the first committed)
        $second = $this->get("/api/v1/links/{$link->token}");
        $second->assertStatus(410)
            ->assertJsonPath('error.code', 'link_consumed');

        // Link must be marked consumed in DB after the first successful resolution
        $link->refresh();
        expect($link->consumed_at)->not->toBeNull();
    });

    it('allows multiple resolutions of a non-single-use link', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->postJson(
            "/api/v1/attachments/{$this->attachmentId}/links",
            ['ttl_hours' => 24, 'is_single_use' => false],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $link = AttachmentLink::where('attachment_id', $this->attachmentId)->first();

        $this->get("/api/v1/links/{$link->token}")->assertStatus(200);
        $this->get("/api/v1/links/{$link->token}")->assertStatus(200);
        $this->get("/api/v1/links/{$link->token}")->assertStatus(200);
    });

    it('returns 429 with rate_limited after exceeding the public link throttle budget', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->postJson(
            "/api/v1/attachments/{$this->attachmentId}/links",
            ['ttl_hours' => 24, 'is_single_use' => false],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $link = AttachmentLink::where('attachment_id', $this->attachmentId)->first();

        for ($i = 0; $i < 30; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
                ->get("/api/v1/links/{$link->token}")
                ->assertStatus(200);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.10.10.10'])
            ->get("/api/v1/links/{$link->token}");

        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'rate_limited');
    });

});
