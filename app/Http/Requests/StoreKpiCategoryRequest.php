<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreKpiCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageOperationalSettings();
    }

    /**
     * `code` is derived from the name (slugified), not entered directly --
     * KPI categories are fully admin-configurable data, so there's no
     * fixed vocabulary of codes to choose from. Uniqueness is scoped per
     * company: Company A and Company B can each have their own "toolbox
     * meeting" category without colliding, since only one of them owns it
     * (or it's global, company_id null, in which case it must be globally
     * unique among other global categories).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::slug($this->input('name'), '_'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                Rule::unique('kpi_categories', 'code')->where(fn ($q) => $q->where('company_id', $this->input('company_id'))),
            ],
            'short_label' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'is_negative' => ['boolean'],
            'show_on_dashboard' => ['boolean'],
            'count_in_dashboard_stats' => ['boolean'],
            'supports_quick_attendance' => ['boolean'],
            'requires_approval' => ['boolean'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:20'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A KPI category with this name already exists for this scope (global or the selected company).',
        ];
    }
}
