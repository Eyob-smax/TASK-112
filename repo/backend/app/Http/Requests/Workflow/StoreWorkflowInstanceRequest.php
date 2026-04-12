<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage workflow instances');
    }

    public function rules(): array
    {
        return [
            'workflow_template_id' => ['required', 'uuid', 'exists:workflow_templates,id'],
            'record_type'          => ['required', 'string', 'in:document,sales_document,return,configuration_version'],
            'record_id'            => ['required', 'uuid'],
            'context'              => ['nullable', 'array'],
        ];
    }
}
