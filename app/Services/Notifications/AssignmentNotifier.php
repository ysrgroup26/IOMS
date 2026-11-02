<?php

namespace App\Services\Notifications;

use App\Models\CorrectiveAction;
use App\Models\Task;
use App\Models\User;
use App\Services\NotificationService;

/**
 * v2.141.0 -- WHEN WORK IS HANDED TO SOMEBODY, THEY ARE TOLD.
 *
 * Nobody was: a task or a corrective action could be assigned, and the
 * assignee found out only by opening the right register. Called from the
 * model hooks on Task and CorrectiveAction, so every module that creates
 * one (Tasks, Incidents, Investigations, NCR, HSE Inspections, Equipment)
 * notifies the same way. Never notifies a person about work they assigned
 * to themselves. Each notification carries its route so the menu offers
 * "Open" only to someone who can reach it (ADR 064).
 */
class AssignmentNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function task(Task $task): void
    {
        $assignee = $this->assignee($task->assigned_user_id);

        if (! $assignee) {
            return;
        }

        $due = $task->due_date ? ' Tenggat '.$task->due_date->format('d M Y').'.' : '';

        $this->notifications->send(
            'task.assigned',
            $assignee,
            'Task assigned: '.($task->task_number ?? $task->title),
            trim($task->title.' -- diserahkan kepada Anda oleh '.$this->actorName().'.'.$due),
            route('tasks.show', $task),
            $task,
            ['route' => 'tasks.show'],
            'task:'.$task->id.':assigned:'.$assignee->id.':'.now()->format('YmdHis'),
            'task:'.$task->id,
        );
    }

    public function correctiveAction(CorrectiveAction $action): void
    {
        $assignee = $this->assignee($action->assigned_to);

        if (! $assignee) {
            return;
        }

        $due = $action->due_date ? ' Tenggat '.$action->due_date->format('d M Y').'.' : '';

        $this->notifications->send(
            'capa.assigned',
            $assignee,
            'Corrective action assigned',
            trim(mb_strimwidth((string) $action->action, 0, 140, '…').' -- diserahkan kepada Anda oleh '.$this->actorName().'.'.$due),
            route('corrective-actions.index'),
            $action,
            ['route' => 'corrective-actions.index'],
            'capa:'.$action->id.':assigned:'.$assignee->id.':'.now()->format('YmdHis'),
            'capa:'.$action->id,
        );
    }

    private function assignee(?int $userId): ?User
    {
        if (! $userId || $userId === auth()->id()) {
            return null;
        }

        $user = User::find($userId);

        return $user && $user->is_active ? $user : null;
    }

    private function actorName(): string
    {
        return auth()->user()?->displayName() ?? 'IOMS';
    }
}
