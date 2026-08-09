<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRosterPatternRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $rosterPattern = $this->route('rosterPattern');
        $tenantCompanyIds = Company::query()->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('roster_patterns', 'name')
                    ->where(fn ($q) => $q->where('company_id', $this->input('company_id')))
                    ->ignore($rosterPattern->id),
            ],
            'code' => ['nullable', 'string', 'max:20'],
            'days_on' => ['required', 'integer', 'min:1', 'max:365'],
            'days_off' => ['required', 'integer', 'min:1', 'max:365'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
