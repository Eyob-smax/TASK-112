<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class StoreReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller enforces view authorization on the sales document
    }

    public function rules(): array
    {
        return [
            'reason_code'         => ['required', 'string', 'in:defective,wrong_item,not_as_described,changed_mind,other'],
            'reason_detail'       => ['nullable', 'string', 'max:2000'],
            'return_amount'       => ['required', 'numeric', 'min:0.01'],
            'restock_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
