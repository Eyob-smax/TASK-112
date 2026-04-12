<?php

use App\Application\Document\DocumentService;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Document\Contracts\DocumentRepositoryInterface;
use App\Exceptions\Document\DocumentArchivedException;
use App\Infrastructure\Security\EncryptionService;
use App\Infrastructure\Security\FingerprintService;
use App\Infrastructure\Security\WatermarkEventService;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
        $user = Mockery::mock(User::class)->makePartial();
        $user->id = 'user-uuid';
        $user->department_id = 'dept-uuid';
        $user->shouldReceive('hasRole')->with(['admin', 'manager', 'auditor'])->andReturn(false);

        $created = Mockery::mock(Document::class)->makePartial();
        $created->id = 'doc-uuid';
        $created->shouldReceive('toArray')->andReturn([
            'id'                 => 'doc-uuid',
            'status'             => 'draft',
            'owner_id'           => 'user-uuid',
            'department_id'      => 'dept-uuid',
            'access_control_scope' => 'department',
        ]);
        $created->shouldReceive('load')->with(['department', 'owner'])->andReturn($created);

        $capturedPayload = null;
        Mockery::mock('alias:App\\Models\\Document')
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function (array $payload) use (&$capturedPayload) {
                $capturedPayload = $payload;
                return true;
            }))
            ->andReturn($created);

        $this->auditRepo->shouldReceive('record')->once()->andReturn(null);

        $result = $this->service->create($user, [
            'title'                => 'Policy Handbook',
            'document_type'        => 'policy',
            'department_id'        => 'dept-uuid',
            'description'          => 'Baseline policy',
            'access_control_scope' => 'department',
        ], '127.0.0.1');

        expect($capturedPayload['owner_id'])->toBe('user-uuid')
            ->and($capturedPayload['status'])->toBe('draft')
            ->and($capturedPayload['is_archived'])->toBeFalse()
            ->and($capturedPayload['department_id'])->toBe('dept-uuid')
            ->and($result)->toBe($created);
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
        $this->docsRepo->shouldReceive('createVersion')
            ->once()
            ->with('doc-uuid', Mockery::capture($capturedData))
            ->andReturn(Mockery::mock(DocumentVersion::class)->makePartial());

        $this->auditRepo->shouldReceive('record')->once()->andReturn(null);

        $this->service->createVersion($user, $doc, $file, [], '127.0.0.1');

        // Verify that file_path is a JSON-encoded encryption envelope
        $decoded = json_decode($capturedData['file_path'], true);
        expect($decoded)->toHaveKeys(['ciphertext', 'iv', 'key_id']);
        expect($decoded['key_id'])->toBe('v1');
    });

});
