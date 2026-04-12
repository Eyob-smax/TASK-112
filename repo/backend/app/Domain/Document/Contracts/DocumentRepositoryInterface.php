<?php

namespace App\Domain\Document\Contracts;

/**
 * Contract for document and version persistence operations.
 *
 * Implementation: App\Infrastructure\Persistence\EloquentDocumentRepository (Prompt 4+)
 */
interface DocumentRepositoryInterface
{
    public function findById(string $id): mixed;

    /**
     * Find a specific version of a document.
     */
    public function findVersion(string $documentId, int $versionNumber): mixed;

    /**
     * Get the current (latest) version of a document.
     */
    public function currentVersion(string $documentId): mixed;

    /**
     * Archive a document atomically — sets status, archived_at, and archived_by.
     * Throws if the document is already archived.
     */
    public function archive(string $documentId, string $archivedBy): void;

    /**
     * Create a new version for an existing document.
     * Automatically supersedes the previous current version.
     *
     * @return mixed The new version record
     */
    public function createVersion(string $documentId, array $versionData): mixed;
}
