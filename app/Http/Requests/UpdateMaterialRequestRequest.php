<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\InCurrentTenant;

class UpdateMaterialRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_date' => ['required', 'date'],
            'company_id' => ['required', new InCurrentTenant('companies')],
            'project_id' => ['nullable', new InCurrentTenant('projects')],
            'department_id' => ['nullable', new InCurrentTenant('departments')],
            'status' => ['required', 'in:draft,submitted'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['nullable', 'integer', 'exists:material_request_items,id'],
            'items.*.item_name' => ['required', 'string', 'max:255'],
            'items.*.specification' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.remarks' => ['nullable', 'string', 'max:255'],
            'items.*.reference_image' => ['nullable', 'image', 'max:4096'],
        ];
    }
}
