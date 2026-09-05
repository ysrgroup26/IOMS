<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Rules\InCurrentTenant;

class StoreKpiRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', new InCurrentTenant('employees')],
            'kpi_category_id' => ['required', 'exists:kpi_categories,id'],
            'record_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
