<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class VoidSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller enforces void authorization
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
