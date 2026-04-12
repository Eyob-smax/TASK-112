<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentDownloadRecord;
use App\Models\DocumentVersion;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for document version upload, retrieval, and controlled download.
 */
describe('Document Versions', function () {

    beforeEach(function () {
        Storage::fake('local');
        putenv('ATTACHMENT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));

        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Legal', 'code' => 'LEG']);

        $this->manager = User::create([
            'username'      => 'ver_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Version Manager',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->manager->assignRole('manager');

        $this->doc = Document::create([
            'title'                => 'Legal Compliance Policy',
            'document_type'        => 'policy',
            'department_id'        => $this->dept->id,
            'owner_id'             => $this->manager->id,
            'status'               => 'draft',
            'access_control_scope' => 'department',
            'is_archived'          => false,
        ]);
    });

    // -------------------------------------------------------------------------
    // Version upload
    // -------------------------------------------------------------------------

    it('uploads first version and assigns version_number=1', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $file = UploadedFile::fake()->create('policy.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/documents/{$this->doc->id}/versions",
            ['file' => $file],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.version_number', 1)
            ->assertJsonPath('data.status', 'current')
            ->assertJsonPath('data.original_filename', 'policy.pdf');
    });

    it('uploads second version, increments to version_number=2, first becomes superseded', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $file1 = UploadedFile::fake()->create('policy_v1.pdf', 100, 'application/pdf');
        $this->postJson(
            "/api/v1/documents/{$this->doc->id}/versions",
            ['file' => $file1],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        )->assertStatus(201);

        $file2 = UploadedFile::fake()->create('policy_v2.pdf', 150, 'application/pdf');
        $response = $this->postJson(
            "/api/v1/documents/{$this->doc->id}/versions",
            ['file' => $file2],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.version_number', 2)
            ->assertJsonPath('data.status', 'current');

        // Verify first version is now superseded
        $v1 = DocumentVersion::where('document_id', $this->doc->id)
            ->where('version_number', 1)
            ->first();

        expect($v1->status->value)->toBe('superseded');
    });

    it('returns 409 when uploading a version to an archived document', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->doc->update([
            'status'      => 'archived',
            'is_archived' => true,
            'archived_at' => now(),
            'archived_by' => $this->manager->id,
        ]);

        $file = UploadedFile::fake()->create('policy.pdf', 100, 'application/pdf');

        $response = $this->postJson(
            "/api/v1/documents/{$this->doc->id}/versions",
            ['file' => $file],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'document_archived');
    });

    // -------------------------------------------------------------------------
    // Version show
    // -------------------------------------------------------------------------

    it('returns 200 with version metadata on show', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $file = UploadedFile::fake()->create('policy.pdf', 100, 'application/pdf');
        $upload = $this->postJson(
            "/api/v1/documents/{$this->doc->id}/versions",
            ['file' => $file],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $versionId = $upload->json('data.id');

        $response = $this->getJson("/api/v1/documents/{$this->doc->id}/versions/{$versionId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $versionId)
            ->assertJsonPath('data.version_number', 1);
    });

    // -------------------------------------------------------------------------
    // Version download
    // -------------------------------------------------------------------------

    it('downloads a PDF version and applies watermark — X-Watermark-Applied is true', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->doc->update(['status' => 'published']);

        // Generate a real minimal PDF via TCPDF so FPDI can parse and stamp it
        $tcpdf = new \TCPDF();
        $tcpdf->AddPage();
        $tcpdf->SetFont('helvetica', '', 10);
        $tcpdf->Cell(0, 10, 'Meridian Policy Document', 0, 1);
        $pdfContent = $tcpdf->Output('', 'S');

        $file = UploadedFile::fake()->createWithContent('policy.pdf', $pdfContent);

        $upload = $this->postJson(
            "/api/v1/documents/{$this->doc->id}/versions",
            ['file' => $file],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );
        $upload->assertStatus(201);

        $versionId = $upload->json('data.id');

        $response = $this->get("/api/v1/documents/{$this->doc->id}/versions/{$versionId}/download");

        $response->assertStatus(200);
        expect($response->headers->get('X-Watermark-Recorded'))->toBe('true');
        expect($response->headers->get('X-Watermark-Applied'))->toBe('true');
    });

    it('records a download event with watermark_applied=true for PDF downloads', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->doc->update(['status' => 'published']);

        // Real PDF so watermark stamping succeeds and watermark_applied is recorded as true
        $tcpdf = new \TCPDF();
        $tcpdf->AddPage();
        $tcpdf->SetFont('helvetica', '', 10);
        $tcpdf->Cell(0, 10, 'Report', 0, 1);
        $pdfContent = $tcpdf->Output('', 'S');

        $file = UploadedFile::fake()->createWithContent('report.pdf', $pdfContent);

        $upload = $this->postJson(
            "/api/v1/documents/{$this->doc->id}/versions",
            ['file' => $file],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $versionId = $upload->json('data.id');

        $this->get("/api/v1/documents/{$this->doc->id}/versions/{$versionId}/download");

        $record = DocumentDownloadRecord::where('document_version_id', $versionId)->first();
        expect($record)->not->toBeNull();
        expect($record->downloaded_by)->toBe($this->manager->id);
        expect($record->watermark_applied)->toBeTrue();
        expect($record->watermark_text)->toContain($this->manager->username);
        expect($record->is_pdf)->toBeTrue();
    });

    it('fails closed when PDF watermarking fails and does not record a download event', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $this->doc->update(['status' => 'published']);

        $file = UploadedFile::fake()->create('broken.pdf', 10, 'application/pdf');

        $upload = $this->postJson(
            "/api/v1/documents/{$this->doc->id}/versions",
            ['file' => $file],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );
        $upload->assertStatus(201);

        $versionId = $upload->json('data.id');

        $response = $this->get("/api/v1/documents/{$this->doc->id}/versions/{$versionId}/download");

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'pdf_watermark_failed');

        expect(DocumentDownloadRecord::where('document_version_id', $versionId)->count())->toBe(0);
    });

});
