<?php

namespace App\Http\Requests;

use App\Models\EmployeeCaseAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * v2.69.0 -- recording a disciplinary action against a case.
 *
 * `effective_until` is validated as on-or-after `issued_at` rather than
 * merely being a date: a warning that expired before it was issued is not
 * a data-entry preference, it is a record that cannot mean anything. It
 * stays optional because not every action lapses -- termination does not.
 */
class StoreEmployeeCaseActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManageEmployeeCases();
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(EmployeeCaseAction::TYPES)],
            'issued_at' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => 'action',
            'issued_at' => 'issue date',
            'effective_until' => 'valid until',
            'reference_number' => 'letter reference',
        ];
    }
}
