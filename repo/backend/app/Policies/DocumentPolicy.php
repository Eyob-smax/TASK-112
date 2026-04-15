<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Admin bypasses all checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    /**
     * Any user with 'view documents' permission can list documents.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view documents');
    }

    /**
     * A user can view a document if they have the permission and the document
     * is in their department — or they have cross-department/system-wide scope.
     */
    public function view(User $user, Document $document): bool
    {
        return $user->can('view documents')
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user));
    }

    /**
     * Any user with 'create documents' permission can create a document.
     */
    public function create(User $user): bool
    {
        return $user->can('create documents');
    }

    /**
     * A user can update a document if:
     *   - They have the update permission
     *   - The document is in their department or they have cross-department scope
     *   - The document is not archived
     */
    public function update(User $user, Document $document): bool
    {
        // Archived check is handled by the service layer (returns 409, not 403)
        return $user->can('update documents')
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user));
    }

    /**
     * A user can archive a document if they have the permission and the document is in
     * their department (or they have cross-scope access).
     * The already-archived check is handled by the service layer (returns 409, not 403).
     */
    public function archive(User $user, Document $document): bool
    {
        return $user->can('archive documents')
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user));
    }

    /**
     * A user can soft-delete a document if they can archive it (same permission tier)
     * and the document is in their department or they have cross-department scope.
     */
    public function delete(User $user, Document $document): bool
    {
        return $user->can('archive documents')
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user));
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    protected function inSameDepartment(User $user, Document $document): bool
    {
        return $user->department_id !== null
            && $user->department_id === $document->department_id;
    }

    protected function hasCrossScope(User $user): bool
    {
        return $user->hasRole(['admin', 'manager', 'auditor']);
    }
}
