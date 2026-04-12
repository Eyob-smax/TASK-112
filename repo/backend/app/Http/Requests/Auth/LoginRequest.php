<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Login is a public endpoint — authorization is always granted.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     *
     * Note: No minimum password length is enforced here.
     * Password complexity requirements apply only when creating/changing a password
     * (via PasswordPolicy value object), not at login time.
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * Custom error messages.
     */
    public function messages(): array
    {
        return [
            'username.required' => 'A username is required.',
            'password.required' => 'A password is required.',
        ];
    }
}
