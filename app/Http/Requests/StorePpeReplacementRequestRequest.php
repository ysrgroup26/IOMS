<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\InCurrentTenant;

class StorePpeReplacementRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeDistribution();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:draft,submitted'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.employee_ppe_id' => ['required', 'exists:employee_ppe,id'],
            'items.*.project_id' => ['nullable', new InCurrentTenant('projects')],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
            'items.*.documentation_photo' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
