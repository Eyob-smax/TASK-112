<?php

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
 * No-mock HTTP coverage for document version endpoints using real local disk.
 */
describe('Document Version Endpoints No-Mock Storage', function () {

    beforeEach(function () {
        putenv('ATTACHMENT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
        Storage::disk('local')->deleteDirectory('documents');

        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Version Dept', 'code' => 'VNM']);

        $this->manager = User::create([
            'username' => 'nomock_version_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name' => 'NoMock Version Manager',
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->manager->assignRole('manager');

        $this->document = Document::create([
            'title' => 'NoMock Version Document',
            'document_type' => 'policy',
            'department_id' => $this->dept->id,
            'owner_id' => $this->manager->id,
            'status' => 'published',
            'access_control_scope' => 'department',
            'is_archived' => false,
        ]);
    });

    it('covers versions store, index, show, and download without Storage::fake', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $pdf = new \TCPDF();
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 10, 'No-mock document version content', 0, 1);
        $pdfContent = $pdf->Output('', 'S');

        $file = UploadedFile::fake()->createWithContent('nomock-policy.pdf', $pdfContent);

        $store = $this->postJson(
            "/api/v1/documents/{$this->document->id}/versions",
            ['file' => $file],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $store->assertStatus(201)
            ->assertJsonPath('data.version_number', 1);

        $versionId = $store->json('data.id');

        expect(Storage::disk('local')->allFiles('documents'))->not->toBeEmpty();

        $this->getJson("/api/v1/documents/{$this->document->id}/versions")
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $versionId);

        $this->getJson("/api/v1/documents/{$this->document->id}/versions/{$versionId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $versionId);

        $this->get("/api/v1/documents/{$this->document->id}/versions/{$versionId}/download")
            ->assertStatus(200);
    });
});
