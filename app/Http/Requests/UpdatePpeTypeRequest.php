<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePpeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeMaster();
    }

    public function rules(): array
    {
        // Same bug class as UpdateKpiCategoryRequest (found via a
        // systematic sweep after fixing that one): route('ppe_type')
        // doesn't match the actual {ppeType} route parameter, so this
        // was always null and ->id always threw. The controller already
        // type-hints `PpeType $ppeType`, so the bound model is reachable
        // as a request property instead.
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'integer'],
            'replacement_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['boolean'],
        ];
    }
}
