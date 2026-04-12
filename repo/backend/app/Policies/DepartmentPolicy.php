<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
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
        return $user->can('view departments') || $user->can('manage departments');
    }

    public function view(User $user, Department $department): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('manage departments');
    }

    public function update(User $user, Department $department): bool
    {
        return $user->can('manage departments');
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->can('manage departments');
    }
}
