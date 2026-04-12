<?php

namespace App\Http\Requests\Admin;

use App\Domain\Auth\ValueObjects\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserPasswordRequest extends FormRequest
{
    /**
     * Only admin role may reset passwords.
     */
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin') ?? false;
    }

    /**
     * Validates the new password against PasswordPolicy complexity rules.
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', function (string $attr, mixed $val, \Closure $fail) {
                foreach (PasswordPolicy::violations($val) as $violation) {
                    $fail($violation);
                }
            }],
        ];
    }
}
