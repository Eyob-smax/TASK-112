<?php

namespace App\Policies;

use App\Models\ReturnRecord;
use App\Models\User;

class ReturnRecordPolicy
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
     * A user can view a return record if they can view sales and their department
     * matches the parent SalesDocument's department (or they have cross-department scope).
     */
    public function view(User $user, ReturnRecord $returnRecord): bool
    {
        $hasPermission = $user->can('view sales') || $user->can('manage sales');

        if (!$hasPermission) {
            return false;
        }

        if ($this->hasCrossScope($user)) {
            return true;
        }

        // Scope to department via the parent sales document
        $salesDocument = $returnRecord->salesDocument;

        if ($salesDocument === null) {
            return false;
        }

        return $user->department_id !== null
            && $user->department_id === $salesDocument->department_id;
    }

    /**
     * A user can update a return record if they have manage sales permission and the
     * return's parent SalesDocument is in their department (or they have cross-scope).
     */
    public function update(User $user, ReturnRecord $returnRecord): bool
    {
        if (!$user->can('manage sales')) {
            return false;
        }

        if ($this->hasCrossScope($user)) {
            return true;
        }

        $salesDocument = $returnRecord->salesDocument;

        return $salesDocument !== null
            && $user->department_id !== null
            && $user->department_id === $salesDocument->department_id;
    }

    /**
     * A user can complete a return using the same department-scoped logic as update.
     */
    public function complete(User $user, ReturnRecord $returnRecord): bool
    {
        return $this->update($user, $returnRecord);
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    protected function hasCrossScope(User $user): bool
    {
        return $user->hasRole(['manager', 'auditor']);
    }
}
