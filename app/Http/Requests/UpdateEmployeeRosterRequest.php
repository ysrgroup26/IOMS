<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Project;
use App\Models\RosterPattern;
use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRosterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantShiftIds = Shift::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantRosterPatternIds = RosterPattern::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        return [
            'shift_id' => ['nullable', Rule::in($tenantShiftIds)],
            'roster_pattern_id' => ['nullable', Rule::in($tenantRosterPatternIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            'site_name' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'cycle_start_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['active', 'completed', 'cancelled'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
