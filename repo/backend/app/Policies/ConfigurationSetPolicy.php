<?php

namespace App\Policies;

use App\Models\ConfigurationSet;
use App\Models\User;

class ConfigurationSetPolicy
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
        return $user->can('manage configuration') || $user->can('view configuration');
    }

    public function view(User $user, ConfigurationSet $set): bool
    {
        if (!($user->can('manage configuration') || $user->can('view configuration'))) {
            return false;
        }

        // Admin/manager can view any set regardless of department
        if ($user->hasRole(['admin', 'manager'])) {
            return true;
        }

        // Non-privileged users: only system-wide (null department) or their own department
        return $set->department_id === null || $set->department_id === $user->department_id;
    }

    public function create(User $user): bool
    {
        return $user->can('manage configuration');
    }

    public function update(User $user, ConfigurationSet $set): bool
    {
        if (!$user->can('manage configuration')) {
            return false;
        }

        // Admin/manager can update any set
        if ($user->hasRole(['admin', 'manager'])) {
            return true;
        }

        // Others can only update system-wide or same-department sets
        return $set->department_id === null || $set->department_id === $user->department_id;
    }

    /**
     * Managing rollouts (canary start, promote, rollback) requires the
     * 'manage rollouts' permission, separate from general configuration management.
     * Also enforces department scope: non-admin/manager users cannot manage rollouts
     * for sets outside their department.
     */
    public function manageRollout(User $user, ConfigurationSet $set): bool
    {
        if (!$user->can('manage rollouts')) {
            return false;
        }

        if ($user->hasRole(['admin', 'manager'])) {
            return true;
        }

        return $set->department_id === null || $set->department_id === $user->department_id;
    }
}
