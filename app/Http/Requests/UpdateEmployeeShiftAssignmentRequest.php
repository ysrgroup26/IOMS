<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeShiftAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        $tenantShiftIds = Shift::whereIn('company_id', Company::query()->pluck('id'))->pluck('id');

        return [
            'shift_id' => ['required', Rule::in($tenantShiftIds)],
            'effective_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:effective_date'],
            'status' => ['nullable', Rule::in(['active', 'ended', 'cancelled'])],
            'notes' => ['nullable', 'string'],
        ];
    }
}
