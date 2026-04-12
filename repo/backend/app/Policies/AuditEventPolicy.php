<?php

namespace App\Policies;

use App\Models\AuditEvent;
use App\Models\User;

class AuditEventPolicy
{
    /**
     * CRITICAL: This policy intentionally does NOT implement before() with an
     * admin bypass. Access to audit events is governed by explicit role membership
     * (admin + auditor only) — not the general admin shortcut. This ensures that
     * even admin users are explicitly associated with the auditing privilege.
     */

    /**
     * Only admin and auditor roles may list audit events.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(['admin', 'auditor']);
    }

    /**
     * Only admin and auditor roles may view individual audit events.
     */
    public function view(User $user, AuditEvent $event): bool
    {
        return $user->hasRole(['admin', 'auditor']);
    }
}
