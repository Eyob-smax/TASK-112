<?php

namespace App\Http\Requests\Configuration;

use Illuminate\Foundation\Http\FormRequest;

class StoreConfigurationSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage configuration');
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'uuid', 'exists:departments,id'],
        ];
    }
}
