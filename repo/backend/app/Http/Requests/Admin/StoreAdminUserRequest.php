<?php

namespace App\Http\Requests\Admin;

use App\Domain\Auth\ValueObjects\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdminUserRequest extends FormRequest
{
    /**
     * Only admin role may create users.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * Validation rules for admin user creation.
     *
     * Password complexity is enforced via PasswordPolicy value object,
     * ensuring the same rules apply as documented in the security spec.
     */
    public function rules(): array
    {
        return [
            'username'      => ['required', 'string', 'max:100', 'unique:users,username'],
            'display_name'  => ['required', 'string', 'max:255'],
            'email'         => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password'      => ['required', 'string', function (string $attr, mixed $val, \Closure $fail) {
                foreach (PasswordPolicy::violations($val) as $violation) {
                    $fail($violation);
                }
            }],
            'role'          => ['required', 'string', 'exists:roles,name'],
            'department_id' => ['required', 'uuid', 'exists:departments,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'username.unique' => 'This username is already taken.',
            'email.unique'    => 'This email address is already registered.',
            'role.exists'     => 'The specified role does not exist.',
        ];
    }
}
