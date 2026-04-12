<?php

namespace App\Http\Requests\Attachment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentLinkRequest extends FormRequest
{
    /**
     * Authorization is handled in the controller via AttachmentPolicy::view().
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for share link creation.
     *
     * TTL is validated against the hard cap of 72 hours (LinkTtlConstraint::MAX_TTL_HOURS).
     * The service layer additionally clamps any out-of-range values as a defensive measure.
     */
    public function rules(): array
    {
        return [
            'ttl_hours'      => ['required', 'integer', 'min:1', 'max:72'],
            'is_single_use'  => ['nullable', 'boolean'],
            'ip_restriction' => ['nullable', 'ip'],
        ];
    }

    public function messages(): array
    {
        return [
            'ttl_hours.required' => 'A TTL in hours is required.',
            'ttl_hours.max'      => 'TTL cannot exceed 72 hours.',
            'ip_restriction.ip'  => 'IP restriction must be a valid IPv4 or IPv6 address.',
        ];
    }
}
