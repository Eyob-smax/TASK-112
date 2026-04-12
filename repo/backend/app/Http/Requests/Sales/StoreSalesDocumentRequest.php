<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create sales');
    }

    public function rules(): array
    {
        return [
            'site_code'               => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'department_id'           => ['required', 'uuid', 'exists:departments,id'],
            'notes'                   => ['nullable', 'string', 'max:5000'],
            'line_items'              => ['nullable', 'array'],
            'line_items.*.product_code' => ['required', 'string', 'max:100'],
            'line_items.*.description'  => ['nullable', 'string', 'max:500'],
            'line_items.*.quantity'     => ['required', 'numeric', 'min:0.0001'],
            'line_items.*.unit_price'   => ['required', 'numeric', 'min:0'],
        ];
    }
}
