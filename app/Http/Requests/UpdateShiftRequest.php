<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\IdentifierCase;

class UpdateShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    /**
     * v2.130.0 -- an identifier is stored in the case it is read in.
     *
     * Before validation, so the UNIQUE rule below compares the value that
     * will actually be stored: normalising afterwards would let `gaj` pass
     * a uniqueness check against a stored `GAJ` and then collide on write.
     * @see \App\Support\IdentifierCase
     */
    protected function prepareForValidation(): void
    {
        $this->merge(IdentifierCase::in($this->only(['code']), ['code']));
    }

    public function rules(): array
    {
        $shift = $this->route('shift');
        $tenantCompanyIds = Company::query()->pluck('id');

        return [
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('shifts', 'code')
                    ->where(fn ($q) => $q->where('company_id', $this->input('company_id')))
                    ->ignore($shift->id),
            ],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'break_duration_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'is_active' => ['boolean'],
        ];
    }
}
