<?php

namespace App\Application\Attachment;

use App\Domain\Attachment\Contracts\AttachmentRepositoryInterface;
use App\Domain\Attachment\Enums\AllowedMimeType;
use App\Domain\Attachment\Enums\AttachmentStatus;
use App\Domain\Attachment\ValueObjects\FileConstraints;
use App\Domain\Attachment\ValueObjects\LinkTtlConstraint;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Exceptions\Attachment\AttachmentCapacityExceededException;
use App\Exceptions\Attachment\AttachmentExpiredException;
use App\Exceptions\Attachment\AttachmentRevokedException;
use App\Exceptions\Attachment\DuplicateAttachmentException;
use App\Exceptions\Attachment\InvalidMimeTypeException;
use App\Exceptions\Attachment\LinkConsumedException;
use App\Exceptions\Attachment\LinkExpiredException;
use App\Exceptions\Attachment\LinkRevokedException;
use App\Infrastructure\Security\EncryptionService;
use App\Infrastructure\Security\ExpiryEvaluator;
use App\Infrastructure\Security\FingerprintService;
use App\Models\Attachment;
use App\Models\AttachmentLink;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Orchestrates attachment and evidence lifecycle operations.
 *
 * Responsibilities:
 *   - Upload files with MIME magic-byte validation, dedup check, encryption, and storage
 *   - Create expiring LAN share links with optional single-use and IP restriction
 *   - Resolve share links: validate usability, decrypt content, consume if single-use
 *   - Revoke attachments (status update + soft delete)
 *   - Process expired attachments (batch status transition)
 *
 * CRITICAL INVARIANTS:
 *   - MIME check uses finfo magic bytes (not just Content-Type header)
 *   - Fingerprint deduplication is GLOBAL — not scoped to a single record
 *   - File content is AES-256-CBC encrypted at rest; path string is ALSO encrypted
 *   - consumeLink() uses lockForUpdate() in the repository — concurrent-safe
 *   - Link resolution records a LinkConsume audit event for every resolution
 */
class AttachmentService
{
    public function __construct(
        private readonly AttachmentRepositoryInterface $attachments,
        private readonly AuditEventRepositoryInterface $audit,
        private readonly EncryptionService $encryption,
        private readonly FingerprintService $fingerprint,
        private readonly ExpiryEvaluator $expiry,
    ) {}

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------

    /**
     * Upload and store an attachment for a business record.
     *
     * Pipeline:
     *   1. finfo magic-bytes MIME check (MIME spoofing prevention)
     *   2. Count check: ≤ 20 active attachments per record
     *   3. SHA-256 fingerprint + global dedup check
     *   4. AES-256-CBC encrypt file content in memory
     *   5. Write encrypted JSON envelope to storage/app/attachments/{year}/{month}/{uuid}.enc
     *   6. Encrypt the path string for DB storage
     *   7. Create Attachment record with computed expires_at
     *
     * @param string   $recordType Fully-qualified model class name (e.g. 'App\Models\Document')
     * @param string   $recordId   UUID of the associated business record
     * @param int|null $validityDays Validity override; falls back to config default (365)
     *
     * @throws ValidationException                  If MIME type is not permitted
     * @throws AttachmentCapacityExceededException  If record already has 20 attachments
     * @throws DuplicateAttachmentException         If SHA-256 fingerprint already exists globally
     */
    public function upload(
        User $user,
        string $recordType,
        string $recordId,
        UploadedFile $file,
        ?int $validityDays,
        string $ipAddress,
        ?string $parentDepartmentId = null,
    ): Attachment {
        // 1. Magic-bytes MIME check — prevents spoofing beyond Laravel's declared-type validation
        $finfo        = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file->getRealPath());
        $declaredMime = $file->getClientMimeType();

        if (!is_string($detectedMime) || $detectedMime === '') {
            throw ValidationException::withMessages([
                'file' => ['Unable to determine uploaded file MIME type.'],
            ]);
        }

        // Enforce strict declared-vs-detected consistency for evidence uploads.
        if (is_string($declaredMime)
            && $declaredMime !== ''
            && strtolower($declaredMime) !== strtolower($detectedMime)
        ) {
            throw new InvalidMimeTypeException($declaredMime, $detectedMime);
        }

        if (!AllowedMimeType::tryFromMime($detectedMime)) {
            throw ValidationException::withMessages([
                'file' => ['File type not permitted by server-side MIME inspection.'],
            ]);
        }

        // 2. Count check — fail fast before expensive fingerprint and encryption operations
        $count = $this->attachments->countActiveForRecord($recordType, $recordId);

        if (FileConstraints::wouldExceedFileCount($count)) {
            throw new AttachmentCapacityExceededException(FileConstraints::MAX_FILES_PER_RECORD);
        }

        // 3. SHA-256 fingerprint + global dedup
        $sha256 = $this->fingerprint->computeFromPath($file->getRealPath());

        if ($this->attachments->fingerprintExists($sha256)) {
            throw new DuplicateAttachmentException($sha256);
        }

        // 4. Encrypt file content in memory
        $plaintext = file_get_contents($file->getRealPath());
        $encrypted = $this->encryption->encrypt($plaintext);

        // 5. Write encrypted JSON envelope: {"ciphertext":"...","iv":"...","key_id":"v1"}
        $year         = now()->format('Y');
        $month        = now()->format('m');
        $uuid         = Str::uuid()->toString();
        $relativePath = "attachments/{$year}/{$month}/{$uuid}.enc";

        Storage::disk('local')->put($relativePath, json_encode($encrypted));

        // 6. Encrypt the storage path string for DB storage
        $encryptedPath = json_encode($this->encryption->encrypt($relativePath));

        // 7. Compute validity and create DB record
        $days      = $validityDays ?? (int) config('meridian.attachments.default_validity_days', 365);
        $expiresAt = now()->addDays($days);

        $attachment = Attachment::create([
            'record_type'        => $recordType,
            'record_id'          => $recordId,
            'original_filename'  => $file->getClientOriginalName(),
            'mime_type'          => $detectedMime,
            'file_size_bytes'    => $file->getSize(),
            'sha256_fingerprint' => $sha256,
            'encrypted_path'     => $encryptedPath,
            'encryption_key_id'  => $this->encryption->getActiveKeyId(),
            'status'             => AttachmentStatus::Active->value,
            'validity_days'      => $days,
            'expires_at'         => $expiresAt,
            'uploaded_by'        => $user->id,
            'department_id'      => $parentDepartmentId ?? $user->department_id,
        ]);

        $afterHash = hash('sha256', json_encode($attachment->toArray()));
        $this->recordAudit(AuditAction::Create, $user->id, Attachment::class, $attachment->id, $ipAddress, afterHash: $afterHash);

        return $attachment;
    }

    // -------------------------------------------------------------------------
    // Share Links
    // -------------------------------------------------------------------------

    /**
     * Generate a LAN share link for an active attachment.
     *
     * @param int    $ttlHours     Requested TTL in hours (clamped to 72 if out of range)
     * @param bool   $isSingleUse  Whether the link should be consumed on first resolution
     * @param string|null $ipRestriction Optional IP address restriction
     *
     * @throws AttachmentExpiredException  If the attachment has passed its validity window
     * @throws AttachmentRevokedException  If the attachment has been revoked or is not active
     */
    public function createLink(
        User $user,
        Attachment $attachment,
        int $ttlHours,
        bool $isSingleUse,
        ?string $ipRestriction,
        string $ipAddress
    ): AttachmentLink {
        if ($this->expiry->isAttachmentExpired($attachment)) {
            throw new AttachmentExpiredException();
        }

        if ($attachment->status !== AttachmentStatus::Active) {
            throw new AttachmentRevokedException();
        }

        // Clamp TTL defensively (form validation should already enforce this)
        if (!LinkTtlConstraint::isTtlAllowed($ttlHours)) {
            $ttlHours = LinkTtlConstraint::clampTtl($ttlHours);
        }

        $token = bin2hex(random_bytes(32)); // 64 hex characters

        $link = AttachmentLink::create([
            'attachment_id'  => $attachment->id,
            'token'          => $token,
            'expires_at'     => now()->addHours($ttlHours),
            'is_single_use'  => $isSingleUse,
            'created_by'     => $user->id,
            'ip_restriction' => $ipRestriction,
        ]);

        $this->recordAudit(AuditAction::LinkCreate, $user->id, AttachmentLink::class, $link->id, $ipAddress);

        return $link;
    }

    /**
     * Resolve a LAN share link and return decrypted file content.
     *
     * Validates usability (expiry, revocation, consumption), decrypts the attachment,
     * and for single-use links atomically marks it consumed. Always records a
     * LinkConsume audit event for traceability.
     *
     * @return array{content: string, mime_type: string, filename: string}
     *
     * @throws LinkExpiredException        If the link has passed its TTL
     * @throws LinkRevokedException        If the link was administratively revoked
     * @throws LinkConsumedException       If a single-use link was already consumed
     * @throws AttachmentExpiredException  If the underlying attachment has expired
     * @throws AttachmentRevokedException  If the underlying attachment was revoked
     */
    public function resolveLink(
        string $token,
        string $requestIp,
        ?string $resolverUserId = null,
        ?string $resolverUserAgent = null,
    ): array
    {
        $link = $this->attachments->findLinkByToken($token);

        if ($link === null) {
            abort(404, 'Share link not found.');
        }

        // Usability checks — order matters: check expiry before revocation before consumption
        if ($this->expiry->isLinkExpired($link)) {
            throw new LinkExpiredException();
        }

        if ($this->expiry->isLinkRevoked($link)) {
            throw new LinkRevokedException();
        }

        if ($this->expiry->isLinkConsumed($link)) {
            throw new LinkConsumedException();
        }

        // Optional IP restriction enforcement
        if ($link->ip_restriction !== null && $link->ip_restriction !== $requestIp) {
            abort(403, 'IP address not permitted for this link.');
        }

        $attachment = $link->attachment;

        if ($this->expiry->isAttachmentExpired($attachment)) {
            throw new AttachmentExpiredException();
        }

        if ($attachment->status === AttachmentStatus::Revoked) {
            throw new AttachmentRevokedException();
        }

        // HIGH-1: Atomically consume single-use links BEFORE decrypting/serving content.
        // consumeLink() uses SELECT FOR UPDATE in a transaction — concurrent-safe.
        // If consumption fails (race loser: already consumed by a concurrent request), throw
        // immediately before any content is served, ensuring fail-closed single-use semantics.
        if ($link->is_single_use) {
            $consumed = $this->attachments->consumeLink($link->id, $resolverUserId);
            if (!$consumed) {
                throw new LinkConsumedException();
            }
        }

        // Decrypt path → read encrypted file envelope → decrypt content
        $pathData     = json_decode($attachment->encrypted_path, true);
        $relativePath = $this->encryption->decrypt(
            $pathData['ciphertext'],
            $pathData['iv'],
            $pathData['key_id']
        );

        $fileEnvelope = json_decode(Storage::disk('local')->get($relativePath), true);
        $plaintext    = $this->encryption->decrypt(
            $fileEnvelope['ciphertext'],
            $fileEnvelope['iv'],
            $fileEnvelope['key_id']
        );

        // Record a LinkConsume audit event for every resolution (not just single-use)
        $this->recordAudit(
            AuditAction::LinkConsume,
            $resolverUserId,
            AttachmentLink::class,
            $link->id,
            $requestIp,
            payload: [
                'resolver_user_agent' => $resolverUserAgent,
                'resolved_via'        => $resolverUserId !== null ? 'authenticated' : 'token_only',
            ],
        );

        return [
            'content'   => $plaintext,
            'mime_type' => $attachment->mime_type,
            'filename'  => $attachment->original_filename,
        ];
    }

    // -------------------------------------------------------------------------
    // Revocation and Expiry Processing
    // -------------------------------------------------------------------------

    /**
     * Revoke an attachment: update status to revoked and soft-delete the record.
     */
    public function revokeAttachment(User $user, Attachment $attachment, string $ipAddress): void
    {
        $beforeHash = hash('sha256', json_encode($attachment->toArray()));
        $attachment->update(['status' => AttachmentStatus::Revoked->value]);
        $afterHash  = hash('sha256', json_encode($attachment->refresh()->toArray())); // after status update, before soft-delete
        $attachment->delete(); // soft-delete

        $this->recordAudit(AuditAction::Delete, $user->id, Attachment::class, $attachment->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);
    }

    /**
     * Batch-transition active attachments that have passed their validity window to 'expired'.
     *
     * Returns the number of attachments transitioned.
     */
    public function processExpiredAttachments(): int
    {
        $attachments = Attachment::where('status', AttachmentStatus::Active->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($attachments as $attachment) {
            $beforeHash = hash('sha256', json_encode($attachment->toArray()));
            $attachment->update(['status' => AttachmentStatus::Expired->value]);
            $afterHash  = hash('sha256', json_encode($attachment->refresh()->toArray()));

            $this->recordAudit(
                AuditAction::Update,
                null,
                Attachment::class,
                $attachment->id,
                '127.0.0.1',
                payload:    ['reason' => 'validity_window_expired'],
                beforeHash: $beforeHash,
                afterHash:  $afterHash,
            );
        }

        return count($attachments);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
