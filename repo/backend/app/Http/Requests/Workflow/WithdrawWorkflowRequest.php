<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller checks authorization
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
