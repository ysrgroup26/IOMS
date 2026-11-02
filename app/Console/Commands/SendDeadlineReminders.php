<?php

namespace App\Console\Commands;

use App\Models\CorrectiveAction;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;

/**
 * v2.141.0 -- DEADLINES TELL THE PERSON WHO OWNS THEM.
 *
 * Hourly. For each active tenant -- run INSIDE that tenant, because the
 * registers are tenant-scoped and the scheduler has no tenant of its own
 * (the same reason `approvals:escalate` silently did nothing) -- tasks and
 * corrective actions due tomorrow get one reminder, and overdue ones one
 * warning per day. Deduplication keys make every run idempotent: the job
 * may run 24 times a day and still tells each person once.
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'notifications:deadlines';

    protected $description = 'Remind assignees of tasks and corrective actions that are due tomorrow or overdue.';

    public function handle(NotificationService $notifications): int
    {
        $sent = 0;
        $current = app(CurrentTenant::class);
        $previous = $current->get();

        foreach (Tenant::where('status', Tenant::STATUS_ACTIVE)->get() as $tenant) {
            $current->set($tenant);

            try {
                $sent += $this->tasks($notifications) + $this->correctiveActions($notifications);
            } finally {
                $current->set($previous);
            }
        }

        $this->info("Sent {$sent} deadline notification(s).");

        return self::SUCCESS;
    }

    private function tasks(NotificationService $notifications): int
    {
        $sent = 0;
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        Task::query()->openStatus()->whereNotNull('assigned_user_id')->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $tomorrow)
            ->each(function (Task $task) use ($notifications, $today, $tomorrow, &$sent) {
                $assignee = User::find($task->assigned_user_id);
                if (! $assignee || ! $assignee->is_active) {
                    return;
                }

                $due = $task->due_date->toDateString();
                $overdue = $due < $today;
                if (! $overdue && $due !== $tomorrow) {
                    return;   // due today was already reminded yesterday
                }

                $sent += (int) (bool) $notifications->send(
                    $overdue ? 'task.overdue' : 'task.due_soon',
                    $assignee,
                    ($overdue ? 'Task overdue: ' : 'Task due tomorrow: ').($task->task_number ?? $task->title),
                    $task->title.' -- tenggat '.$task->due_date->format('d M Y').'.',
                    route('tasks.show', $task),
                    $task,
                    ['route' => 'tasks.show'],
                    $overdue ? "task:{$task->id}:overdue:{$today}" : "task:{$task->id}:due:{$due}",
                    'task:'.$task->id,
                );
            });

        return $sent;
    }

    private function correctiveActions(NotificationService $notifications): int
    {
        $sent = 0;
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        CorrectiveAction::query()
            ->whereIn('status', [CorrectiveAction::STATUS_OPEN, CorrectiveAction::STATUS_IN_PROGRESS])
            ->whereNotNull('assigned_to')->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $tomorrow)
            ->each(function (CorrectiveAction $action) use ($notifications, $today, $tomorrow, &$sent) {
                $assignee = User::find($action->assigned_to);
                if (! $assignee || ! $assignee->is_active) {
                    return;
                }

                $due = $action->due_date->toDateString();
                $overdue = $due < $today;
                if (! $overdue && $due !== $tomorrow) {
                    return;
                }

                $sent += (int) (bool) $notifications->send(
                    $overdue ? 'capa.overdue' : 'capa.due_soon',
                    $assignee,
                    $overdue ? 'Corrective action overdue' : 'Corrective action due tomorrow',
                    mb_strimwidth((string) $action->action, 0, 140, '…').' -- tenggat '.$action->due_date->format('d M Y').'.',
                    route('corrective-actions.index'),
                    $action,
                    ['route' => 'corrective-actions.index'],
                    $overdue ? "capa:{$action->id}:overdue:{$today}" : "capa:{$action->id}:due:{$due}",
                    'capa:'.$action->id,
                );
            });

        return $sent;
    }
}
