<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateKpiCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageOperationalSettings();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::slug($this->input('name'), '_'),
        ]);
    }

    public function rules(): array
    {
        // Root cause of the reported exception: this used to read
        // $this->route('kpi_category') (snake_case), but the actual
        // route parameter is {kpiCategory} (camelCase) -- see
        // routes/web.php's settings.kpi-categories.update definition.
        // That naming mismatch meant route('kpi_category') always
        // returned null, so ->id threw "Attempt to read property 'id'
        // on null". The controller already type-hints
        // `KpiCategory $kpiCategory`, so route model binding has
        // already resolved the full model by the time this runs --
        // $this->kpiCategory (a dynamic property Laravel exposes for
        // every resolved route-model-bound parameter) is the correct,
        // idiomatic way to reach it here, and isn't vulnerable to a
        // string-name mismatch the way route('some_string') is.
        $category = $this->kpiCategory;

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                Rule::unique('kpi_categories', 'code')
                    ->where(fn ($q) => $q->where('company_id', $this->input('company_id')))
                    ->ignore($category->id),
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
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'A KPI category with this name already exists for this scope (global or the selected company).',
        ];
    }
}
