<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Document\Contracts\DocumentRepositoryInterface;
use App\Domain\Document\Enums\DocumentStatus;
use App\Domain\Document\Enums\VersionStatus;
use App\Exceptions\Document\DocumentArchivedException;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;

/**
 * Eloquent implementation of DocumentRepositoryInterface.
 *
 * CRITICAL INVARIANTS:
 *   - archive() uses lockForUpdate() to prevent concurrent archive races
 *   - createVersion() computes max(version_number) + 1 INSIDE the transaction
 *   - DB unique constraint (document_id, version_number) provides a safety net
 */
class EloquentDocumentRepository implements DocumentRepositoryInterface
{
    /**
     * Find a document by its UUID.
     */
    public function findById(string $id): mixed
    {
        return Document::find($id);
    }

    /**
     * Find a specific version of a document by version number.
     */
    public function findVersion(string $documentId, int $versionNumber): mixed
    {
        return DocumentVersion::where('document_id', $documentId)
            ->where('version_number', $versionNumber)
            ->first();
    }

    /**
     * Get the current (latest) version of a document.
     */
    public function currentVersion(string $documentId): mixed
    {
        return DocumentVersion::where('document_id', $documentId)
            ->where('status', VersionStatus::Current->value)
            ->first();
    }

    /**
     * Archive a document atomically.
     *
     * Sets status, is_archived, archived_at, archived_by in a single transaction.
     * Also transitions all document versions to the archived status.
     *
     * @throws DocumentArchivedException If the document is already archived.
     */
    public function archive(string $documentId, string $archivedBy): void
    {
        DB::transaction(function () use ($documentId, $archivedBy) {
            $doc = Document::lockForUpdate()->findOrFail($documentId);

            if ($doc->is_archived) {
                throw new DocumentArchivedException();
            }

            $doc->update([
                'status'      => DocumentStatus::Archived->value,
                'is_archived' => true,
                'archived_at' => now(),
                'archived_by' => $archivedBy,
            ]);

            // Freeze all versions — prevents confusion about "current" version after archive
            DocumentVersion::where('document_id', $documentId)
                ->update(['status' => VersionStatus::Archived->value]);
        });
    }

    /**
     * Create a new version for an existing document.
     *
     * Automatically supersedes the previous current version.
     * The new version number is max(existing) + 1, computed inside the transaction.
     *
     * @return mixed The new DocumentVersion record
     */
    public function createVersion(string $documentId, array $versionData): mixed
    {
        return DB::transaction(function () use ($documentId, $versionData) {
            // Compute the next version number inside the transaction to prevent
            // race conditions from concurrent version uploads.
            $max = DocumentVersion::where('document_id', $documentId)->max('version_number') ?? 0;

            // Supersede the current active version before creating the new one
            DocumentVersion::where('document_id', $documentId)
                ->where('status', VersionStatus::Current->value)
                ->update(['status' => VersionStatus::Superseded->value]);

            return DocumentVersion::create([
                ...$versionData,
                'document_id'    => $documentId,
                'version_number' => $max + 1,
                'status'         => VersionStatus::Current->value,
            ]);
        });
    }
}
