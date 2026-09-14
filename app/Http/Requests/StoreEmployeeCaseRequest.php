<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCase;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v2.69.0. Follows the same rule this codebase applies to every id it
 * accepts: never a raw `exists:` rule, always a list scoped to the
 * current tenant first, so a crafted request body cannot reach another
 * customer's employee or hand a case to another customer's user.
 */
class StoreEmployeeCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageEmployeeCases();
    }

    public function rules(): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantEmployeeIds = Employee::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantId = app(CurrentTenant::class)->id();
        $tenantUserIds = User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'employee_id' => ['required', Rule::in($tenantEmployeeIds)],
            'category' => ['required', Rule::in(EmployeeCase::CATEGORIES)],
            'severity' => ['required', Rule::in(EmployeeCase::SEVERITIES)],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:5000'],
            'reported_at' => ['required', 'date'],
            'assigned_to' => ['nullable', Rule::in($tenantUserIds)],
        ];
    }

    public function attributes(): array
    {
        return [
            'employee_id' => 'employee',
            'assigned_to' => 'handler',
            'reported_at' => 'date raised',
        ];
    }
}
