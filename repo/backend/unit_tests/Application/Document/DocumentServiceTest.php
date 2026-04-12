<?php

use App\Application\Document\DocumentService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Document\Contracts\DocumentRepositoryInterface;
use App\Exceptions\Document\DocumentArchivedException;
use App\Infrastructure\Security\EncryptionService;
use App\Infrastructure\Security\FingerprintService;
use App\Infrastructure\Security\WatermarkEventService;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Unit tests for DocumentService.
 *
 * Repository and infrastructure dependencies are mocked — no database required.
 * Storage::fake('local') is used where the service writes to disk.
 */
describe('DocumentService', function () {

    beforeEach(function () {
        $this->docsRepo  = Mockery::mock(DocumentRepositoryInterface::class);
        $this->auditRepo = Mockery::mock(AuditEventRepositoryInterface::class);
        $this->encryption = Mockery::mock(EncryptionService::class);
        $this->fingerprint = Mockery::mock(FingerprintService::class);
        $this->watermark   = Mockery::mock(WatermarkEventService::class);

        $this->auditRepo->shouldReceive('record')->andReturn(null)->byDefault();

        $this->service = new DocumentService(
            $this->docsRepo,
            $this->auditRepo,
            $this->encryption,
            $this->fingerprint,
            $this->watermark,
        );
    });

    afterEach(function () {
        Mockery::close();
    });

    it('creates a document with draft status and owner set to the acting user', function () {
        $dept = Department::create(['name' => 'Test Dept', 'code' => 'TDT']);
        $user = User::create([
            'username'      => 'doc_creator',
            'password_hash' => Hash::make('Password1!'),
            'display_name'  => 'Doc Creator',
            'department_id' => $dept->id,
            'is_active'     => true,
        ]);

        $result = $this->service->create($user, [
            'title'                => 'Policy Handbook',
            'document_type'        => 'policy',
            'department_id'        => $dept->id,
            'description'          => 'Baseline policy',
            'access_control_scope' => 'department',
        ], '127.0.0.1');

        expect($result->owner_id)->toBe($user->id)
            ->and($result->department_id)->toBe($dept->id)
            ->and((bool) $result->is_archived)->toBeFalse();
    });

    it('throws DocumentArchivedException when updating an archived document', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 'user-uuid';

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->is_archived = true;

        expect(fn () => $this->service->update($user, $doc, ['title' => 'New Title'], '127.0.0.1'))
            ->toThrow(DocumentArchivedException::class);
    });

    it('throws DocumentArchivedException when creating a version on an archived document', function () {
        Storage::fake('local');

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 'user-uuid';

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->id          = 'doc-uuid';
        $doc->is_archived = true;

        $file = Mockery::mock(UploadedFile::class)->makePartial();

        expect(fn () => $this->service->createVersion($user, $doc, $file, [], '127.0.0.1'))
            ->toThrow(DocumentArchivedException::class);
    });

    it('delegates archive operation to the repository', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 'user-uuid';

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->id = 'doc-uuid';
        $doc->shouldReceive('fresh')->andReturn($doc);

        $this->docsRepo->shouldReceive('archive')->once()->with('doc-uuid', 'user-uuid');
        $this->auditRepo->shouldReceive('record')->once()->andReturn(null);

        $result = $this->service->archive($user, $doc, '127.0.0.1');

        expect($result)->toBe($doc);
    });

    it('throws DocumentArchivedException on archive when repository signals already archived', function () {
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 'user-uuid';

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->id = 'doc-uuid';

        $this->docsRepo->shouldReceive('archive')
            ->once()
            ->andThrow(new DocumentArchivedException());

        expect(fn () => $this->service->archive($user, $doc, '127.0.0.1'))
            ->toThrow(DocumentArchivedException::class);
    });

    it('uses the encrypted path JSON format when creating a version', function () {
        Storage::fake('local');

        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 'user-uuid';

        $doc = Mockery::mock(Document::class)->makePartial();
        $doc->id          = 'doc-uuid';
        $doc->is_archived = false;

        $file = UploadedFile::fake()->create('policy.pdf', 100, 'application/pdf');

        $this->fingerprint
            ->shouldReceive('computeFromPath')
            ->once()
            ->andReturn(str_repeat('a', 64));

        $this->encryption
            ->shouldReceive('encrypt')
            ->once()
            ->andReturn(['ciphertext' => 'ct', 'iv' => 'iv', 'key_id' => 'v1']);

        $capturedData = [];
        $versionMock = Mockery::mock(DocumentVersion::class)->makePartial();
        $versionMock->id = 'version-uuid';
        $this->docsRepo->shouldReceive('createVersion')
            ->once()
            ->with('doc-uuid', Mockery::capture($capturedData))
            ->andReturn($versionMock);

        $this->auditRepo->shouldReceive('record')->once()->andReturn(null);

        $this->service->createVersion($user, $doc, $file, [], '127.0.0.1');

        // Verify that file_path is a JSON-encoded encryption envelope
        $decoded = json_decode($capturedData['file_path'], true);
        expect($decoded)->toHaveKeys(['ciphertext', 'iv', 'key_id']);
        expect($decoded['key_id'])->toBe('v1');
    });

});
