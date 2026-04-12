<?php

namespace App\Http\Requests\Configuration;

use Illuminate\Foundation\Http\FormRequest;

class RolloutConfigurationVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller checks manageRollout policy
    }

    public function rules(): array
    {
        return [
            'target_type'  => ['required', 'string', 'in:store,user'],
            'target_ids'   => ['required', 'array', 'min:1'],
            'target_ids.*' => ['required', 'uuid', 'distinct'],
            // eligible_count is intentionally absent — computed server-side from
            // authoritative store to prevent client-supplied denominator manipulation.
        ];
    }
}
