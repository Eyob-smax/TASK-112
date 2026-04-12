<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller enforces update authorization
    }

    public function rules(): array
    {
        return [
            'notes'                   => ['nullable', 'string', 'max:5000'],
            'line_items'              => ['nullable', 'array'],
            'line_items.*.product_code' => ['required', 'string', 'max:100'],
            'line_items.*.description'  => ['nullable', 'string', 'max:500'],
            'line_items.*.quantity'     => ['required', 'numeric', 'min:0.0001'],
            'line_items.*.unit_price'   => ['required', 'numeric', 'min:0'],
        ];
    }
}
