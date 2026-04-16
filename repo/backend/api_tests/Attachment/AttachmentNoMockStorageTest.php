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
 * No-mock HTTP coverage for attachment and link endpoints using real local disk.
 */
describe('Attachment Endpoints No-Mock Storage', function () {

    beforeEach(function () {
        putenv('ATTACHMENT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
        Storage::disk('local')->deleteDirectory('attachments');

        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'NoMock Dept', 'code' => 'NMK']);

        $this->manager = User::create([
            'username' => 'nomock_manager',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name' => 'NoMock Manager',
            'department_id' => $this->dept->id,
            'is_active' => true,
        ]);
        $this->manager->assignRole('manager');

        $this->document = Document::create([
            'title' => 'NoMock Attachment Target',
            'document_type' => 'report',
            'department_id' => $this->dept->id,
            'owner_id' => $this->manager->id,
            'status' => 'draft',
            'access_control_scope' => 'department',
            'is_archived' => false,
        ]);
    });

    it('covers upload, list, show, delete, create-link, and public resolve without Storage::fake', function () {
        Sanctum::actingAs($this->manager, ['*'], 'sanctum');

        $file = UploadedFile::fake()->createWithContent(
            'nomock-evidence.pdf',
            '%PDF-1.4 no mock storage integration content'
        );

        $upload = $this->postJson(
            "/api/v1/records/document/{$this->document->id}/attachments",
            ['files' => [$file]],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $upload->assertStatus(201)
            ->assertJsonPath('data.0.status', 'active');

        $attachmentId = $upload->json('data.0.id');

        expect(Storage::disk('local')->allFiles('attachments'))->not->toBeEmpty();

        $this->getJson("/api/v1/records/document/{$this->document->id}/attachments")
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $attachmentId]);

        $this->getJson("/api/v1/attachments/{$attachmentId}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $attachmentId);

        $createLink = $this->postJson(
            "/api/v1/attachments/{$attachmentId}/links",
            ['ttl_hours' => 2, 'is_single_use' => false],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $createLink->assertStatus(201)
            ->assertJsonPath('data.attachment_id', $attachmentId);

        $url = $createLink->json('data.url');
        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->get("/api/v1/links/{$token}")
            ->assertStatus(200);

        $this->deleteJson(
            "/api/v1/attachments/{$attachmentId}",
            [],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        )->assertStatus(204);

        expect(Attachment::withTrashed()->whereKey($attachmentId)->first())->not->toBeNull();
    });
});
