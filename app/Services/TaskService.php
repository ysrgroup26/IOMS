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
    public function __construct(private readonly NumberGeneratorService $numberGenerator) {}

    public function createTask(array $data, int $createdBy): Task
    {
        $data['created_by'] = $createdBy;
        $data['task_number'] = $this->generateTaskNumber($data['company_id'] ?? null);
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
     * Milestone 3: delegates to the centralized, lock-safe Numbering
     * Engine (`NumberGeneratorService`) instead of the old unlocked
     * `ORDER BY ... DESC LIMIT 1` read-then-write. Same
     * TSK-{YEAR}-{00001} shape as before by default.
     */
    public function generateTaskNumber(?int $companyId = null): string
    {
        return $this->numberGenerator->generate('task', $companyId);
    }
}
