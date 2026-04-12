<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class CompleteReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller enforces manage sales authorization
    }

    public function rules(): array
    {
        return [];
    }
}
