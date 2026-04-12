<?php

namespace App\Domain\Attachment\Contracts;

/**
 * Contract for attachment and link persistence operations.
 *
 * Implementation: App\Infrastructure\Persistence\EloquentAttachmentRepository (Prompt 4+)
 */
interface AttachmentRepositoryInterface
{
    public function findById(string $id): mixed;

    /**
     * Find an attachment link by its opaque token.
     */
    public function findLinkByToken(string $token): mixed;

    /**
     * Count active (non-revoked, non-expired) attachments for a record.
     *
     * @param string $recordType Morph type string
     * @param string $recordId   Record UUID
     */
    public function countActiveForRecord(string $recordType, string $recordId): int;

    /**
     * Atomically consume a single-use link.
     * Sets consumed_at and consumed_by. Returns false if already consumed.
     */
    public function consumeLink(string $linkId, ?string $consumedByUserId): bool;

    /**
     * Check whether a SHA-256 fingerprint already exists for deduplication.
     */
    public function fingerprintExists(string $sha256Fingerprint): bool;
}
