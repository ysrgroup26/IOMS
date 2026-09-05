<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Rules\InCurrentTenant;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'status' => ['required', Rule::in(Task::STATUSES)],
            'task_type' => ['nullable', 'string', 'max:100'],
            'related_module' => ['nullable', 'string', 'max:100'],
            'related_record_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', new InCurrentTenant('companies')],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
        ];
    }
}
