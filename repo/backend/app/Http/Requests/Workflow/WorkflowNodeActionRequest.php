<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared form request for approve, reject, reassign, and add-approver actions.
 *
 * Note: reason is nullable here because the approve and add-approver actions
 * do not require it. Mandatory-reason enforcement for reject/reassign is done
 * at the service layer (WorkflowService), not at the form validation layer.
 */
class WorkflowNodeActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller enforces 'approve workflow nodes' permission
    }

    public function rules(): array
    {
        return [
            'reason'         => ['nullable', 'string', 'max:2000'],
            'target_user_id' => ['nullable', 'uuid', 'exists:users,id'],
        ];
    }
}
