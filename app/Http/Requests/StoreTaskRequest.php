<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user can create a task -- this is a general-
        // purpose engine meant to be usable by future modules broadly,
        // not gated to a specific role. Editing/deleting someone else's
        // task is restricted separately, in the controller.
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(Task::PRIORITIES)],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'task_type' => ['nullable', 'string', 'max:100'],
            'related_module' => ['nullable', 'string', 'max:100'],
            'related_record_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
            'due_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
        ];
    }
}
