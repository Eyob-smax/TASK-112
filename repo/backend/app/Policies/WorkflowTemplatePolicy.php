<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowTemplate;

class WorkflowTemplatePolicy
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
        return $user->can('manage workflow templates') || $user->can('view workflow');
    }

    public function view(User $user, WorkflowTemplate $template): bool
    {
        return ($user->can('manage workflow templates') || $user->can('view workflow'))
            && $this->canAccessTemplate($user, $template);
    }

    public function create(User $user): bool
    {
        return $user->can('manage workflow templates');
    }

    public function update(User $user, WorkflowTemplate $template): bool
    {
        return $user->can('manage workflow templates')
            && $this->canAccessTemplate($user, $template);
    }

    public function delete(User $user, WorkflowTemplate $template): bool
    {
        return $user->can('manage workflow templates')
            && $this->canAccessTemplate($user, $template);
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true if the user is allowed to access the template by department scope.
     *
     * - System-wide templates (department_id = null): only cross-scope roles (admin/manager/auditor).
     * - Department-scoped templates: user must be in the same department OR have cross-scope access.
     */
    protected function canAccessTemplate(User $user, WorkflowTemplate $template): bool
    {
        if ($template->department_id === null) {
            return $this->hasCrossScope($user);
        }

        return ($user->department_id !== null && $user->department_id === $template->department_id)
            || $this->hasCrossScope($user);
    }

    protected function hasCrossScope(User $user): bool
    {
        return $user->hasRole(['admin', 'manager', 'auditor']);
    }
}
