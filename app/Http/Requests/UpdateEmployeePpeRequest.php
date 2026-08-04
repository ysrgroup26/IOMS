<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeePpeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeDistribution();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:issued,in_use,replacement_requested,replacement_approved,replacement_completed,archived'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
