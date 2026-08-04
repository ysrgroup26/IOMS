<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAuthenticationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Self-service only: any authenticated Super Admin/HSE can change
        // their OWN credentials. Route middleware already restricts this
        // to those two roles; this just double-checks a user is present.
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The current password is incorrect.',
        ];
    }
}
