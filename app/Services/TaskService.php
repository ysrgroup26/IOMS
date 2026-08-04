<?php

namespace App\Services;

use App\Models\Task;
use Illuminate\Support\Carbon;

/**
 * Universal Task Engine Foundation service layer (v1.6.4). All task
 * business logic lives here, not in the controller -- TaskController
 * should only handle HTTP concerns (validation via Form Requests,
 * response shaping) and delegate everything else to this class.
 */
class TaskService
{
    public function createTask(array $data, int $createdBy): Task
    {
        $data['created_by'] = $createdBy;
        $data['task_number'] = $this->generateTaskNumber();
        $data['status'] ??= Task::STATUS_OPEN;
        $data['priority'] ??= Task::PRIORITY_MEDIUM;
        $data['task_source'] ??= 'manual';

        return Task::create($data);
    }

    public function updateTask(Task $task, array $data): Task
    {
        $task->update($data);

        return $task->fresh();
    }

    public function deleteTask(Task $task): void
    {
        $task->delete(); // soft delete
    }

    public function assignTask(Task $task, ?int $userId): Task
    {
        $task->update(['assigned_user_id' => $userId]);

        return $task->fresh();
    }

    public function changeStatus(Task $task, string $status): Task
    {
        $update = ['status' => $status];

        // completed_date is set/cleared automatically based on status,
        // rather than requiring the caller to manage it separately.
        if ($status === Task::STATUS_COMPLETED) {
            $update['completed_date'] = Carbon::now();
        } elseif ($task->status === Task::STATUS_COMPLETED) {
            $update['completed_date'] = null;
        }

        $task->update($update);

        return $task->fresh();
    }

    /**
     * Format: TSK-{YYYY}-{sequential number, zero-padded to 5 digits},
     * e.g. TSK-2026-00001. Sequential within a year (matches the existing
     * per-year numbering convention already used elsewhere in the app,
     * e.g. KPI reports), not globally sequential, so numbers stay
     * reasonably short even after years of use.
     */
    public function generateTaskNumber(): string
    {
        $year = now()->format('Y');

        $lastNumber = Task::withTrashed()
            ->where('task_number', 'like', "TSK-{$year}-%")
            ->orderByDesc('task_number')
            ->value('task_number');

        $sequence = $lastNumber
            ? ((int) substr($lastNumber, -5)) + 1
            : 1;

        return sprintf('TSK-%s-%05d', $year, $sequence);
    }
}
