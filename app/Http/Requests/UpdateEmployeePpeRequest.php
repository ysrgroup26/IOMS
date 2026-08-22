<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * v1.11.6 (Production Readiness pass, Part 1). Root cause of "PPE Master
 * cannot edit an existing PPE record": this request only ever validated
 * `status`/`remarks` -- the frontend Edit dialog matched that exactly
 * (Status + Remarks only), so there was genuinely no way to correct a
 * wrong PPE type, issue date, or expiry date on an already-issued item
 * short of deleting and re-issuing. `ppe_type_id`/`issued_date`/
 * `expiry_date` are now optional-but-validated edit fields -- `employee_id`
 * is deliberately NOT included: reassigning an issued item to a different
 * employee is not a "correction," it's effectively a new issuance, and
 * the existing Delete + re-Issue flow already covers that case correctly.
 * There is no `quantity` field on `employee_ppe` (each row is already one
 * physical item; issuing several at once creates several rows via
 * `store()`), so there is nothing to add for that.
 */
class UpdateEmployeePpeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeDistribution();
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:issued,in_use,replacement_requested,replacement_approved,replacement_completed,archived'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'ppe_type_id' => ['sometimes', 'required', 'exists:ppe_types,id'],
            'issued_date' => ['sometimes', 'required', 'date'],
            'expiry_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_date'],
        ];
    }
}
