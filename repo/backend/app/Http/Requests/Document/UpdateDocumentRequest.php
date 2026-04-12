<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    /**
     * Authorization is handled in the controller via DocumentPolicy::update().
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'                => ['sometimes', 'string', 'max:500'],
            'description'          => ['nullable', 'string', 'max:5000'],
            'access_control_scope' => ['sometimes', 'string', 'in:department,cross_department,system_wide'],
        ];
    }
}
