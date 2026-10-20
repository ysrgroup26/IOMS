<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\IdentifierCase;

class UpdateHazardCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }


    /**
     * v2.130.0 -- a hazard category code is an identifier, upper case.
     *
     * Before validation, so the UNIQUE rules below compare the value that
     * will actually be stored. Normalising after validation would let
     * `gaj` pass a uniqueness check against a stored `GAJ` and then collide
     * on write. @see \App\Support\IdentifierCase
     */
    protected function prepareForValidation(): void
    {
        $this->merge(IdentifierCase::in($this->only(['code']), ['code']));
    }

    public function rules(): array
    {
        $hazardCategory = $this->route('hazardCategory');
        $tenantCompanyIds = Company::query()->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('hazard_categories', 'name')
                    ->where(fn ($q) => $q->where('company_id', $this->input('company_id')))
                    ->ignore($hazardCategory->id),
            ],
            'code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ];
    }
}
