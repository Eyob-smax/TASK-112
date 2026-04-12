<?php

use App\Models\Attachment;
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
 * API tests for attachment upload, listing, revocation, and constraint enforcement.
 */
describe('Attachment Upload', function () {

    beforeEach(function () {
        Storage::fake('local');
        putenv('ATTACHMENT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));

        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Compliance', 'code' => 'COMP']);

        $this->staff = User::create([
            'username'      => 'attach_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Attach Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');

        $this->manager = User::create([
            'username'      => 'attach_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Attach Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');

        // The target record for attachment uploads
        $this->doc = Document::create([
            'title'                => 'Evidence Holder',
            'document_type'        => 'report',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);
    });

    // -------------------------------------------------------------------------
    // Upload success
    // -------------------------------------------------------------------------

    it('uploads a valid PDF attachment and returns 201 with attachment metadata', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        $file = UploadedFile::fake()->create('evidence.pdf', 500, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.0.mime_type', 'application/pdf')
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonStructure(['data' => [['id', 'sha256_fingerprint', 'expires_at']]]);
    });

    it('uploads multiple files in a single batch request and returns 201 with an array of results', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        $file1 = UploadedFile::fake()->create('evidence1.pdf', 200, 'application/pdf');
        $file2 = UploadedFile::fake()->create('evidence2.pdf', 300, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file1, $file2]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(201);
        expect(count($response->json('data')))->toBe(2)
            ->and($response->json('data.0.status'))->toBe('active')
            ->and($response->json('data.1.status'))->toBe('active');
    });

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    it('lists attachments for a record and returns 200', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        $response = $this->getJson("/api/v1/records/document/{$this->doc->id}/attachments");

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    });

    // -------------------------------------------------------------------------
    // MIME validation
    // -------------------------------------------------------------------------

    it('returns 422 when uploaded MIME type is not in allowed list', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        // Create a file whose MIME type is not allowed (text/plain)
        $file = UploadedFile::fake()->create('malicious.txt', 10, 'text/plain');

        $response = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        // Laravel's form validation (mimetypes rule) catches this at the request level
        $response->assertStatus(422);
    });

    // -------------------------------------------------------------------------
    // Duplicate fingerprint
    // -------------------------------------------------------------------------

    it('returns 409 when uploading a file with identical SHA-256 fingerprint', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        $file = UploadedFile::fake()->create('report.pdf', 200, 'application/pdf');

        // First upload
        $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        )->assertStatus(201);

        // Second upload with same file content (same fingerprint)
        $file2 = UploadedFile::fake()->create('report.pdf', 200, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file2]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'duplicate_attachment');
    });

    // -------------------------------------------------------------------------
    // MIME header vs. content mismatch
    // -------------------------------------------------------------------------

    it('returns 422 when declared MIME type does not match actual file content', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        // Create an upload whose declared MIME is image/png but whose bytes are PDF.
        $path = tempnam(sys_get_temp_dir(), 'mime_mismatch_');
        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $file = new UploadedFile(
            $path,
            'photo.png',
            'image/png',
            null,
            true
        );

        $response = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_mime_type');

        @unlink($path);
    });

    // -------------------------------------------------------------------------
    // File count limit
    // -------------------------------------------------------------------------

    it('returns 422 when adding a 21st attachment to a record that already has 20', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        // Directly seed 20 active attachment records (bypassing service to avoid 20 uploads)
        for ($i = 0; $i < 20; $i++) {
            Attachment::create([
                'record_type'        => Document::class,
                'record_id'          => $this->doc->id,
                'original_filename'  => "file_{$i}.pdf",
                'mime_type'          => 'application/pdf',
                'file_size_bytes'    => 1024,
                'sha256_fingerprint' => hash('sha256', "unique_content_{$i}"),
                'encrypted_path'     => json_encode(['ciphertext' => 'x', 'iv' => 'y', 'key_id' => 'v1']),
                'encryption_key_id'  => 'v1',
                'status'             => 'active',
                'validity_days'      => 365,
                'expires_at'         => now()->addYear(),
                'uploaded_by'        => $this->staff->id,
                'department_id'      => $this->dept->id,
            ]);
        }

        $file = UploadedFile::fake()->create('overflow.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'attachment_limit_exceeded');
    });

    it('rejects a batch that would exceed the cap without partial inserts', function () {
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        for ($i = 0; $i < 19; $i++) {
            Attachment::create([
                'record_type'        => Document::class,
                'record_id'          => $this->doc->id,
                'original_filename'  => "existing_{$i}.pdf",
                'mime_type'          => 'application/pdf',
                'file_size_bytes'    => 1024,
                'sha256_fingerprint' => hash('sha256', "existing_content_{$i}"),
                'encrypted_path'     => json_encode(['ciphertext' => 'x', 'iv' => 'y', 'key_id' => 'v1']),
                'encryption_key_id'  => 'v1',
                'status'             => 'active',
                'validity_days'      => 365,
                'expires_at'         => now()->addYear(),
                'uploaded_by'        => $this->staff->id,
                'department_id'      => $this->dept->id,
            ]);
        }

        $file1 = UploadedFile::fake()->create('overflow_1.pdf', 100, 'application/pdf');
        $file2 = UploadedFile::fake()->create('overflow_2.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file1, $file2]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'attachment_limit_exceeded');

        $activeCount = Attachment::where('record_type', Document::class)
            ->where('record_id', $this->doc->id)
            ->whereNull('deleted_at')
            ->count();

        expect($activeCount)->toBe(19);
    });

    // -------------------------------------------------------------------------
    // Department isolation
    // -------------------------------------------------------------------------

    it('returns 403 when listing attachments on a record from a different department', function () {
        // Create a second department and a user who belongs only to it
        $otherDept = Department::create(['name' => 'Finance', 'code' => 'FIN']);

        $otherUser = User::create([
            'username'      => 'other_dept_user',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Other Dept User',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $otherUser->assignRole('staff');

        // $this->doc belongs to $this->dept (Compliance) — not Finance
        Sanctum::actingAs($otherUser, ['*'], 'sanctum');

        $response = $this->getJson("/api/v1/records/document/{$this->doc->id}/attachments");

        $response->assertStatus(403);
    });

    it('returns 403 when showing an attachment that belongs to a different department', function () {
        // Upload an attachment in the Compliance department
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        $file = UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf');
        $upload = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );
        $upload->assertStatus(201);
        $attachmentId = $upload->json('data.0.id');

        // A user from a different department tries to fetch the attachment directly
        $otherDept = Department::create(['name' => 'HR', 'code' => 'HR']);

        $otherUser = User::create([
            'username'      => 'hr_viewer',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'HR Viewer',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $otherUser->assignRole('staff');

        Sanctum::actingAs($otherUser, ['*'], 'sanctum');

        $response = $this->getJson("/api/v1/attachments/{$attachmentId}");

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // Upload and delete department scope
    // -------------------------------------------------------------------------

    it('returns 403 when a user from a different department uploads to a record in another department', function () {
        $otherDept = Department::create(['name' => 'Legal', 'code' => 'LGL']);

        $outsider = User::create([
            'username'      => 'attach_outsider_upload',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Attach Outsider Upload',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $outsider->assignRole('staff');

        // $this->doc belongs to $this->dept (Compliance) — outsider is in Legal
        Sanctum::actingAs($outsider, ['*'], 'sanctum');

        $file = UploadedFile::fake()->create('outsider.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(403);
    });

    it('returns 403 when a user from a different department tries to delete an attachment', function () {
        // Upload an attachment as the owner-department staff
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');

        $file = UploadedFile::fake()->create('to_delete.pdf', 100, 'application/pdf');
        $upload = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );
        $upload->assertStatus(201);
        $attachmentId = $upload->json('data.0.id');

        // Outsider from a different department with revoke attachments permission
        $otherDept = Department::create(['name' => 'Procurement', 'code' => 'PRO']);
        $outsider = User::create([
            'username'      => 'attach_outsider_del',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Attach Outsider Delete',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $outsider->assignRole('staff');
        $outsider->givePermissionTo('revoke attachments');

        Sanctum::actingAs($outsider, ['*'], 'sanctum');

        $response = $this->deleteJson("/api/v1/attachments/{$attachmentId}", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        // Has revoke permission but wrong department → 403
        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // Cross-department upload — department attribution
    // -------------------------------------------------------------------------

    it('attachment uploaded by cross-scope manager inherits parent record department, not uploader department', function () {
        // Manager from a DIFFERENT department uploads to $this->doc (Compliance dept)
        $otherDept = Department::create(['name' => 'Finance', 'code' => 'FIN2']);
        $crossManager = User::create([
            'username'      => 'cross_mgr',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Cross Manager',
            'department_id' => $otherDept->id,
            'is_active'     => true,
        ]);
        $crossManager->assignRole('manager');
        $crossManager->givePermissionTo(['upload attachments', 'download attachments', 'view documents']);

        Sanctum::actingAs($crossManager, ['*'], 'sanctum');
        $file = UploadedFile::fake()->create('cross.pdf', 100, 'application/pdf');

        $upload = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );
        $upload->assertStatus(201);
        $attachmentId = $upload->json('data.0.id');

        // The attachment must be tagged to the parent record's department (Compliance),
        // not the uploader's department (Finance)
        $attachment = \App\Models\Attachment::find($attachmentId);
        expect($attachment->department_id)->toBe($this->doc->department_id);

        // Staff from the parent record's department can view the attachment
        Sanctum::actingAs($this->staff, ['*'], 'sanctum');
        $this->getJson("/api/v1/attachments/{$attachmentId}")->assertStatus(200);
    });

    // -------------------------------------------------------------------------
    // Revocation
    // -------------------------------------------------------------------------

    it('revokes an attachment via DELETE and returns 204', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $file = UploadedFile::fake()->create('to_revoke.pdf', 100, 'application/pdf');

        $upload = $this->postJson(
            "/api/v1/records/document/{$this->doc->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        )->assertStatus(201);

        $attachmentId = $upload->json('data.0.id');

        $response = $this->deleteJson("/api/v1/attachments/{$attachmentId}", [], [
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ]);

        $response->assertStatus(204);
    });

});
