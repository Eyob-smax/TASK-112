<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
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
     * A user can list attachments on a record if they have the download permission.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('download attachments');
    }

    /**
     * A user can download/view an attachment if they have the download permission
     * and the attachment belongs to their department (admin and auditor are exempt).
     */
    public function view(User $user, Attachment $attachment): bool
    {
        if ($user->hasRole(['admin', 'auditor'])) {
            return $user->can('download attachments');
        }

        return $user->can('download attachments')
            && $user->department_id !== null
            && $user->department_id === $attachment->department_id;
    }

    /**
     * A user can upload a new attachment if they have the upload permission.
     */
    public function create(User $user): bool
    {
        return $user->can('upload attachments');
    }

    /**
     * A user can revoke (soft-delete) an attachment if they have the revoke permission
     * and the attachment belongs to their department (admin and auditor are exempt).
     */
    public function delete(User $user, Attachment $attachment): bool
    {
        if (!$user->can('revoke attachments')) {
            return false;
        }

        if ($user->hasRole(['admin', 'auditor'])) {
            return true;
        }

        return $user->department_id !== null
            && $user->department_id === $attachment->department_id;
    }
}
