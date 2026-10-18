<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePpeTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canManagePpeMaster();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['required', 'integer'],
            'replacement_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['boolean'],
        ];
    }
}
