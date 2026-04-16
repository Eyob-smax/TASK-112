<?php

namespace App\Application\Document;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Attachment\Enums\AllowedMimeType;
use App\Domain\Document\Contracts\DocumentRepositoryInterface;
use App\Domain\Document\Enums\DocumentStatus;
use App\Exceptions\Document\DocumentArchivedException;
use App\Infrastructure\Security\EncryptionService;
use App\Infrastructure\Security\FingerprintService;
use App\Infrastructure\Security\WatermarkEventService;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Orchestrates document and version lifecycle operations.
 *
 * Responsibilities:
 *   - Create, update, and archive documents with full audit trail
 *   - Create new versions (file store → fingerprint → encrypt path → DB record)
 *   - Resolve encrypted file paths for downloads
 *   - Record download events for successful controlled downloads
 *
 * CRITICAL INVARIANTS:
 *   - Archived documents cannot be updated or receive new versions (enforced here AND in repo)
 *   - Document version file path is encrypted in DB; file content is NOT encrypted on disk
 *   - Successful PDF downloads produce a watermark record reflecting whether stamping succeeded
 */
class DocumentService
{
    public function __construct(
        private readonly DocumentRepositoryInterface $docs,
        private readonly AuditEventRepositoryInterface $audit,
        private readonly EncryptionService $encryption,
        private readonly FingerprintService $fingerprint,
        private readonly WatermarkEventService $watermark,
    ) {}

    // -------------------------------------------------------------------------
    // Document CRUD
    // -------------------------------------------------------------------------

    /**
     * Create a new document with draft status.
     *
     * @param array $data Validated input from StoreDocumentRequest
     */
    public function create(User $user, array $data, string $ipAddress): Document
    {
        $this->assertCreateDepartmentAccess($user, $data['department_id']);

        $doc = Document::create([
            'title'                => $data['title'],
            'document_type'        => $data['document_type'],
            'department_id'        => $data['department_id'],
            'owner_id'             => $user->id,
            'status'               => DocumentStatus::Draft->value,
            'description'          => $data['description'] ?? null,
            'access_control_scope' => $data['access_control_scope'],
            'is_archived'          => false,
        ]);

        $afterHash = hash('sha256', json_encode($doc->toArray()));
        $this->recordAudit(AuditAction::Create, $user->id, Document::class, $doc->id, $ipAddress, afterHash: $afterHash);

        return $doc->load(['department', 'owner']);
    }

    /**
     * Update allowed metadata fields on a non-archived document.
     *
     * @throws DocumentArchivedException If the document is archived.
     */
    public function update(User $user, Document $document, array $data, string $ipAddress): Document
    {
        if ($document->is_archived) {
            throw new DocumentArchivedException();
        }

        $allowed    = ['title', 'description', 'access_control_scope'];
        $beforeHash = hash('sha256', json_encode($document->toArray()));
        $document->update(array_intersect_key($data, array_flip($allowed)));
        $afterHash  = hash('sha256', json_encode($document->fresh()->toArray()));

        $this->recordAudit(AuditAction::Update, $user->id, Document::class, $document->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $document->fresh();
    }

    /**
     * Soft-delete a document.
     *
     * Records a Delete audit event with before/after hashes for compliance.
     */
    public function delete(User $user, Document $document, string $ipAddress): void
    {
        $beforeHash = hash('sha256', json_encode($document->toArray()));
        $document->delete();
        $afterHash = hash('sha256', json_encode($document->toArray()));

        $this->recordAudit(AuditAction::Delete, $user->id, Document::class, $document->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);
    }

    /**
     * Archive a document, freezing it to read-only.
     *
     * Delegates to the repository which uses lockForUpdate() internally.
     *
     * @throws DocumentArchivedException If already archived (thrown by repository).
     */
    public function archive(User $user, Document $document, string $ipAddress): Document
    {
        $beforeHash = hash('sha256', json_encode($document->toArray()));
        $this->docs->archive($document->id, $user->id);
        $afterHash  = hash('sha256', json_encode($document->fresh()->toArray()));

        $this->recordAudit(AuditAction::Archive, $user->id, Document::class, $document->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $document->fresh();
    }

    // -------------------------------------------------------------------------
    // Version Management
    // -------------------------------------------------------------------------

    /**
     * Upload and create a new version for a document.
     *
     * Steps:
     *   1. Guard: reject if document is archived
     *   2. Compute SHA-256 fingerprint from the uploaded temp file
     *   3. Move file to local storage: documents/{year}/{month}/{uuid}.{ext}
     *   4. Encrypt the path string (NOT the file content) and store as JSON
     *   5. Create the version record (repo handles version_number incrementing)
     *
     * @param array $meta Validated metadata from StoreDocumentVersionRequest
     * @throws DocumentArchivedException If the document is archived.
     */
    public function createVersion(
        User $user,
        Document $document,
        UploadedFile $file,
        array $meta,
        string $ipAddress
    ): DocumentVersion {
        if ($document->is_archived) {
            throw new DocumentArchivedException('Cannot add a version to an archived document.');
        }

        $fingerprint = $this->fingerprint->computeFromPath($file->getRealPath());

        $year  = now()->format('Y');
        $month = now()->format('m');
        $uuid  = Str::uuid()->toString();
        $ext   = $file->getClientOriginalExtension()
            ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION)
            ?: 'bin';

        $relativePath = "documents/{$year}/{$month}/{$uuid}.{$ext}";

        // Store the file unencrypted — only the path is encrypted in the DB column
        Storage::disk('local')->putFileAs(
            "documents/{$year}/{$month}",
            $file,
            "{$uuid}.{$ext}"
        );

        // Encrypt the path string for DB storage
        $encryptedPathData = $this->encryption->encrypt($relativePath);
        $encryptedPath     = json_encode($encryptedPathData);

        $version = $this->docs->createVersion($document->id, [
            'file_path'           => $encryptedPath,
            'original_filename'   => $file->getClientOriginalName(),
            'mime_type'           => $file->getMimeType() ?? $file->getClientMimeType(),
            'file_size_bytes'     => $file->getSize(),
            'sha256_fingerprint'  => $fingerprint,
            'page_count'          => isset($meta['page_count']) ? (int) $meta['page_count'] : null,
            'sheet_count'         => isset($meta['sheet_count']) ? (int) $meta['sheet_count'] : null,
            'is_previewable'      => (bool) ($meta['is_previewable'] ?? false),
            'thumbnail_available' => (bool) ($meta['thumbnail_available'] ?? false),
            'created_by'          => $user->id,
            'published_at'        => now(),
        ]);

        $afterHash = hash('sha256', json_encode($version->toArray()));
        $this->recordAudit(AuditAction::Create, $user->id, DocumentVersion::class, $version->id, $ipAddress, afterHash: $afterHash);

        return $version;
    }

    // -------------------------------------------------------------------------
    // Download Helpers
    // -------------------------------------------------------------------------

    /**
     * Decrypt the stored path and return the absolute filesystem path for streaming.
     *
     * The file_path column holds a JSON-encoded array {ciphertext, iv, key_id}
     * produced by EncryptionService::encrypt(). Decrypting yields the relative
     * storage path which is then resolved to an absolute path via Storage::disk.
     */
    public function resolveFilePath(DocumentVersion $version): string
    {
        $encryptedData = json_decode($version->file_path, true);

        $relativePath = $this->encryption->decrypt(
            $encryptedData['ciphertext'],
            $encryptedData['iv'],
            $encryptedData['key_id']
        );

        return Storage::disk('local')->path($relativePath);
    }

    /**
     * Record a controlled download event for a document version.
     *
    * For PDFs: generates a watermark text string containing the downloader's username
     * and timestamp, which is stamped on the PDF by the caller before this is invoked.
     * The $watermarkApplied flag reflects whether the caller successfully applied the stamp.
     *
     * @param bool $watermarkApplied True when FPDI/TCPDF successfully stamped the PDF.
     */
    public function recordDownload(User $user, DocumentVersion $version, string $ipAddress, bool $watermarkApplied = false): void
    {
        $isPdf = ($version->mime_type === AllowedMimeType::Pdf->value);

        $watermarkText = $isPdf
            ? "{$user->username} - " . now()->format('Y-m-d H:i:s')
            : null;

        $this->watermark->recordDownload(
            documentVersionId:  $version->id,
            downloadedByUserId: $user->id,
            ipAddress:          $ipAddress,
            watermarkText:      $watermarkText,
            watermarkApplied:   $watermarkApplied,
            isPdf:              $isPdf,
        );

        $this->recordAudit(AuditAction::Download, $user->id, DocumentVersion::class, $version->id, $ipAddress);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function assertCreateDepartmentAccess(User $user, string $departmentId): void
    {
        if ($user->hasRole(['admin', 'manager', 'auditor'])) {
            return;
        }

        if ($user->department_id !== null && $user->department_id === $departmentId) {
            return;
        }

        throw new AuthorizationException('You are not authorized to create documents for this department.');
    }

    private function recordAudit(
        AuditAction $action,
        ?string $actorId,
        string $auditableType,
        string $auditableId,
        string $ipAddress,
        array $payload = [],
        ?string $beforeHash = null,
        ?string $afterHash = null,
    ): void {
        // Derive a deterministic correlation ID from the request idempotency key when present,
        // so the audit record can be linked back to the originating API request.
        $idempotencyKey = request()->header('X-Idempotency-Key');
        $correlationId  = $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey . ':' . $auditableId . ':' . $action->value)
            : (string) Str::uuid();

        $this->audit->record(
            correlationId:  $correlationId,
            action:         $action,
            actorId:        $actorId,
            auditableType:  $auditableType,
            auditableId:    $auditableId,
            beforeHash:     $beforeHash,
            afterHash:      $afterHash,
            payload:        $payload,
            ipAddress:      $ipAddress,
        );
    }
}
