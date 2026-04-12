<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    /**
     * Only users with 'create documents' permission may create a document.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create documents') ?? false;
    }

    public function rules(): array
    {
        return [
            'title'                => ['required', 'string', 'max:500'],
            'document_type'        => ['required', 'string', 'in:policy,form,procedure,report,other'],
            'description'          => ['nullable', 'string', 'max:5000'],
            'access_control_scope' => ['required', 'string', 'in:department,cross_department,system_wide'],
            'department_id'        => ['required', 'uuid', 'exists:departments,id'],
        ];
    }
}
