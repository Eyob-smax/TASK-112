<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkflowTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage workflow templates');
    }

    public function rules(): array
    {
        return [
            'name'                       => ['required', 'string', 'max:255'],
            'description'                => ['nullable', 'string', 'max:2000'],
            'event_type'                 => ['required', 'string', 'max:100'],
            'department_id'              => ['nullable', 'uuid', 'exists:departments,id'],
            'amount_threshold_min'       => ['nullable', 'numeric', 'min:0'],
            'amount_threshold_max'       => ['nullable', 'numeric', 'min:0', 'gte:amount_threshold_min'],
            'nodes'                      => ['required', 'array', 'min:1'],
            'nodes.*.node_type'          => ['required', 'string', 'in:sequential,parallel,conditional'],
            'nodes.*.node_order'         => ['required', 'integer', 'min:1'],
            'nodes.*.role_required'      => ['nullable', 'uuid', 'exists:roles,id'],
            'nodes.*.user_required'      => ['nullable', 'uuid', 'exists:users,id'],
            'nodes.*.sla_business_days'  => ['nullable', 'integer', 'min:1', 'max:30'],
            'nodes.*.is_parallel'        => ['nullable', 'boolean'],
            'nodes.*.condition_field'    => ['nullable', 'string', 'max:100'],
            'nodes.*.condition_operator' => ['nullable', 'string', 'in:gt,lt,eq,gte,lte'],
            'nodes.*.condition_value'    => ['nullable', 'string', 'max:255'],
            'nodes.*.label'              => ['nullable', 'string', 'max:500'],
        ];
    }
}
