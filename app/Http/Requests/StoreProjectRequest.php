<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\InCurrentTenant;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageProjects();
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', new InCurrentTenant('companies')],
            'name' => ['required', 'string', 'max:255'],
            'vessel_name' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'status' => ['required', 'in:planned,ongoing,completed,cancelled'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
