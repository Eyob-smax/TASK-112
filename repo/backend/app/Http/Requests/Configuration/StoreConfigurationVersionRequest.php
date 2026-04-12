<?php

namespace App\Http\Requests\Configuration;

use Illuminate\Foundation\Http\FormRequest;

class StoreConfigurationVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller enforces policy
    }

    public function rules(): array
    {
        return [
            'payload'              => ['required', 'array'],
            'change_summary'       => ['nullable', 'string', 'max:2000'],
            'rules'                => ['nullable', 'array'],
            'rules.*.rule_type'    => ['required_with:rules', 'string', 'in:coupon,promotion,purchase_limit,blacklist,whitelist,campaign,landing_topic,ad_slot,homepage_module'],
            'rules.*.rule_key'     => ['required_with:rules', 'string', 'max:255'],
            'rules.*.rule_value'   => ['required_with:rules', 'array'],
            'rules.*.is_active'    => ['nullable', 'boolean'],
            'rules.*.priority'     => ['nullable', 'integer', 'min:0', 'max:9999'],
            'rules.*.description'  => ['nullable', 'string', 'max:1000'],
        ];
    }
}
