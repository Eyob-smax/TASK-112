<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Attachment\Contracts\AttachmentRepositoryInterface;
use App\Domain\Attachment\Enums\AttachmentStatus;
use App\Models\Attachment;
use App\Models\AttachmentLink;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of AttachmentRepositoryInterface.
 *
 * CRITICAL INVARIANTS:
 *   - consumeLink() uses lockForUpdate() — atomic single-use consumption
 *   - fingerprintExists() is a GLOBAL check (cross-record), not per-record
 *   - countActiveForRecord() excludes soft-deleted attachments
 */
class EloquentAttachmentRepository implements AttachmentRepositoryInterface
{
    /**
     * Find an attachment by its UUID.
     */
    public function findById(string $id): mixed
    {
        return Attachment::find($id);
    }

    /**
     * Find an attachment link by its opaque token.
     * Eagerly loads the parent attachment for downstream access.
     */
    public function findLinkByToken(string $token): mixed
    {
        return AttachmentLink::where('token', $token)
            ->with('attachment')
            ->first();
    }

    /**
     * Count active (non-revoked, non-expired, non-soft-deleted) attachments for a record.
     *
     * @param string $recordType Morph type string (e.g. 'App\Models\Document')
     * @param string $recordId   Record UUID
     */
    public function countActiveForRecord(string $recordType, string $recordId): int
    {
        return Attachment::where('record_type', $recordType)
            ->where('record_id', $recordId)
            ->where('status', AttachmentStatus::Active->value)
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * Atomically consume a single-use link.
     *
     * Uses SELECT FOR UPDATE to prevent concurrent consumption.
     * Returns false if the link was already consumed or does not exist.
     */
    public function consumeLink(string $linkId, ?string $consumedByUserId): bool
    {
        return DB::transaction(function () use ($linkId, $consumedByUserId) {
            $link = AttachmentLink::lockForUpdate()->find($linkId);

            if ($link === null || $link->consumed_at !== null) {
                return false;
            }

            $link->update([
                'consumed_at' => now(),
                'consumed_by' => $consumedByUserId,
            ]);

            return true;
        });
    }

    /**
     * Check whether a SHA-256 fingerprint already exists for deduplication.
     *
     * NOTE: This is a GLOBAL check — it is not scoped to any particular record.
     * Identical content uploaded to different records is still detected as a duplicate.
     */
    public function fingerprintExists(string $sha256Fingerprint): bool
    {
        return Attachment::where('sha256_fingerprint', $sha256Fingerprint)->exists();
    }
}
