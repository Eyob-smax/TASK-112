<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowInstance;

class WorkflowInstancePolicy
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
     * A user can list/view workflow instances if they have the view workflow permission.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view workflow');
    }

    /**
     * A user can view a specific workflow instance if:
     *   - They have 'view workflow' permission AND
     *   - They are the initiator, OR assigned to any node in this instance,
     *     OR hold a supervisory role (admin/manager).
     */
    public function view(User $user, WorkflowInstance $instance): bool
    {
        if (!$user->can('view workflow')) {
            return false;
        }

        // Supervisory roles see all instances
        if ($this->isSupervisor($user)) {
            return true;
        }

        // Initiator can always view their own instance
        if ($instance->initiated_by === $user->id) {
            return true;
        }

        // Assigned approvers can view instances containing their nodes
        return $instance->nodes()->where('assigned_to', $user->id)->exists();
    }

    /**
     * A user can approve, reject, reassign, or add approvers on a workflow node if
     * they have the 'approve workflow nodes' permission and are either:
     *   - A supervisor (admin/manager), or
     *   - Assigned to at least one node in the instance.
     *
     * Exact node ownership is still enforced at the service layer (guardNodeActionable).
     */
    public function approve(User $user, WorkflowInstance $instance): bool
    {
        if (!$user->can('approve workflow nodes')) {
            return false;
        }

        if ($this->isSupervisor($user)) {
            return true;
        }

        return $instance->nodes()->where('assigned_to', $user->id)->exists();
    }

    /**
     * A workflow instance can be withdrawn by its initiator or a user with
     * workflow-instance management permission.
     */
    public function withdraw(User $user, WorkflowInstance $instance): bool
    {
        if ($instance->initiated_by === $user->id) {
            return true;
        }

        return $user->can('manage workflow instances');
    }

    private function isSupervisor(User $user): bool
    {
        return $user->hasRole(['admin', 'manager']);
    }
}
