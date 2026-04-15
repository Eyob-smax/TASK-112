<?php

namespace App\Policies;

use App\Models\SalesDocument;
use App\Models\User;

class SalesDocumentPolicy
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

    public function viewAny(User $user): bool
    {
        return $user->can('view sales') || $user->can('manage sales');
    }

    /**
     * Department-scoped: user can only view documents in their department unless
     * they have manager or auditor role (cross-department access).
     */
    public function view(User $user, SalesDocument $document): bool
    {
        $hasPermission = $user->can('view sales') || $user->can('manage sales');

        return $hasPermission
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user));
    }

    public function create(User $user): bool
    {
        return $user->can('create sales');
    }

    /**
     * Can create return/exchange records only with manage-sales permission and
     * department scope alignment (or cross-scope role).
     */
    public function createReturn(User $user, SalesDocument $document): bool
    {
        return $user->can('manage sales')
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user));
    }

    /**
     * Can update only if: has manage permission, document is in the user's department
     * (or user has cross-department scope), and document is still editable.
     */
    public function update(User $user, SalesDocument $document): bool
    {
        return $user->can('manage sales')
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user))
            && $document->status->isEditable();
    }

    /**
     * Can complete a sales document only if: has manage permission and document is in
     * the user's department (or user has cross-department scope).
     */
    public function complete(User $user, SalesDocument $document): bool
    {
        return $user->can('manage sales')
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user));
    }

    /**
     * Can link outbound only if: has manage permission and document is in
     * the user's department (or user has cross-department scope).
     */
    public function linkOutbound(User $user, SalesDocument $document): bool
    {
        return $user->can('manage sales')
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user));
    }

    public function void(User $user, SalesDocument $document): bool
    {
        return $user->can('void sales');
    }

    /**
     * Can soft-delete a sales document with manage permission and department scope.
     * Only draft or voided documents may be deleted (completed documents are permanent records).
     */
    public function delete(User $user, SalesDocument $document): bool
    {
        return $user->can('manage sales')
            && ($this->inSameDepartment($user, $document) || $this->hasCrossScope($user));
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    protected function inSameDepartment(User $user, SalesDocument $document): bool
    {
        return $user->department_id !== null
            && $user->department_id === $document->department_id;
    }

    protected function hasCrossScope(User $user): bool
    {
        return $user->hasRole(['manager', 'auditor']);
    }
}
