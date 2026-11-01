<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\CorrectiveAction;
use App\Models\PermitToWork;
use App\Models\Task;
use App\Models\User;
use App\Support\RouteReach;
use Illuminate\Support\Carbon;

/**
 * v2.141.0 -- MY WORK ANSWERS THREE QUESTIONS, AND ONLY WITH WHAT THE
 * SERVER WOULD ALLOW.
 *
 *   waitingOnMe   decisions this person can actually take now: approvals the
 *                 ApprovalEngine would let them decide (authorize(), not just
 *                 "holds an approver role"), and permits whose CURRENT stage
 *                 their company configured them for (PtwWorkflowService).
 *   assignedToMe  open tasks and corrective actions handed to them, overdue
 *                 first, then by deadline, then by priority.
 *   myPermits     permits they requested or are the PIC of, still open.
 *
 * Every item is one shape (kind, title, subtitle, number, status, due,
 * overdue, priority, href) and `href` is set only when RouteReach says this
 * person can open that route -- so a Department User, a My Work account or
 * a plan without the module sees the item without a dead link (ADR 061).
 * Nothing here widens visibility: every query runs under the tenant scopes
 * and is then narrowed to this person.
 */
class MyWorkService
{
    private const LIMIT = 8;

    private const PRIORITY_RANK = ['critical' => 0, 'high' => 1, 'medium' => 2, 'normal' => 2, 'low' => 3];

    private const OPEN_PERMIT_STATUSES = [
        PermitToWork::STATUS_DRAFT,
        PermitToWork::STATUS_SUBMITTED,
        PermitToWork::STATUS_VERIFIED,
        PermitToWork::STATUS_APPROVED,
        PermitToWork::STATUS_ACTIVE,
    ];

    public function __construct(
        private ApprovalEngine $approvals,
        private PtwWorkflowService $ptw,
        private DashboardStatsService $stats,
    ) {}

    /** @return array{waitingOnMe: array, assignedToMe: array, myPermits: array, counts: array} */
    public function for(User $user): array
    {
        $waiting = $this->waitingOnMe($user);
        $assigned = $this->assignedToMe($user);
        $permits = $this->myPermits($user);

        return [
            'waitingOnMe' => array_slice($waiting, 0, self::LIMIT),
            'assignedToMe' => array_slice($assigned, 0, self::LIMIT),
            'myPermits' => array_slice($permits, 0, self::LIMIT),
            'counts' => [
                'waitingOnMe' => count($waiting),
                'assignedToMe' => count($assigned),
                'overdue' => count(array_filter($assigned, fn ($item) => $item['overdue'])),
                'myPermits' => count($permits),
            ],
        ];
    }

    public function waitingOnMe(User $user): array
    {
        $companyIds = $this->stats->resolveCompanyIds(null);
        $items = [];

        // Generic approvals. `approvable` resolves through the module's own
        // CompanyOwnedScope, so a foreign tenant's record is null and
        // authorize() refuses it before any role is considered.
        $pending = Approval::query()
            ->where('status', Approval::STATUS_PENDING)
            ->with('approvable')
            ->latest()
            ->limit(200)
            ->get();

        $seen = [];
        foreach ($pending as $approval) {
            // One item per record and stage: a stage with several approver
            // rows (a position OR a role) is still one decision to take.
            $stageKey = $approval->approvable_type.'#'.$approval->approvable_id.'#'.$approval->step_number;
            if (isset($seen[$stageKey])) {
                continue;
            }

            $subject = $approval->approvable;
            if (! $subject || (! empty($subject->company_id) && ! in_array($subject->company_id, $companyIds, true))) {
                continue;
            }
            if (! $this->approvals->authorize($approval, $user)) {
                continue;
            }

            $seen[$stageKey] = true;
            $final = $this->approvals->isFinalVerification($approval);
            $described = $this->approvals->describe($subject);
            $items[] = $this->item(
                kind: $final ? 'final_verification' : 'approval',
                title: $described['label'],
                subtitle: $final ? 'Menunggu Final Verification Anda' : 'Menunggu persetujuan Anda',
                number: $described['number'] ?: null,
                status: 'pending',
                due: null,
                priority: null,
                route: $described['route'],
                href: $described['url'],
                user: $user,
                sortAt: $approval->created_at,
            );
        }

        // Permits waiting on the stage this person holds. Verify and
        // approve are separate authorities (ADR 067); the stage is derived
        // from the permit's current status, never from the person.
        $permits = PermitToWork::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', [PermitToWork::STATUS_SUBMITTED, PermitToWork::STATUS_VERIFIED])
            ->orderBy('start_datetime')
            ->limit(200)
            ->get();

        foreach ($permits as $permit) {
            // A permit on its company's approval flow waits through its
            // Approval rows (above), where separation of duties applies.
            if ($this->ptw->usesApprovalFlow($permit)) {
                continue;
            }

            $stage = $this->ptw->pendingStage($permit);
            if (! $stage || ! $this->ptw->canAct($user, $permit, $stage)) {
                continue;
            }

            $items[] = $this->item(
                kind: 'ptw_'.$stage,
                title: $permit->documentLabel(),
                subtitle: ($stage === PtwWorkflowService::STAGE_VERIFY ? 'Menunggu verifikasi Anda' : 'Menunggu persetujuan Anda')
                    .($permit->location ? ' · '.$permit->location : ''),
                number: $permit->ptw_number,
                status: $permit->status,
                due: $permit->start_datetime,
                priority: null,
                route: 'permits-to-work.show',
                href: route('permits-to-work.show', $permit->id),
                user: $user,
                sortAt: $permit->start_datetime,
            );
        }

        // The oldest request first: it has waited longest.
        usort($items, fn ($a, $b) => strcmp($a['_sort'] ?? '', $b['_sort'] ?? ''));

        return array_map(fn ($item) => $this->strip($item), $items);
    }

    public function assignedToMe(User $user): array
    {
        $items = [];

        $tasks = Task::query()->assignedTo($user->id)->openStatus()->limit(200)->get();
        foreach ($tasks as $task) {
            $overdue = $task->due_date !== null && $task->due_date->copy()->endOfDay()->isPast();
            $items[] = $this->item(
                kind: 'task',
                title: $task->title,
                subtitle: null,
                number: $task->task_number ?? null,
                status: $task->status,
                due: $task->due_date,
                priority: $task->priority,
                route: 'tasks.show',
                href: route('tasks.show', $task->id),
                user: $user,
                overdue: $overdue,
            );
        }

        $actions = CorrectiveAction::query()
            ->where('assigned_to', $user->id)
            ->whereIn('status', [CorrectiveAction::STATUS_OPEN, CorrectiveAction::STATUS_IN_PROGRESS])
            ->limit(200)
            ->get();
        foreach ($actions as $action) {
            $items[] = $this->item(
                kind: 'capa',
                title: (string) $action->action,
                subtitle: null,
                number: null,
                status: $action->status,
                due: $action->due_date,
                priority: $action->priority,
                route: 'corrective-actions.index',
                href: route('corrective-actions.index'),
                user: $user,
                overdue: $action->due_date !== null && $action->due_date->copy()->endOfDay()->isPast(),
            );
        }

        usort($items, function ($a, $b) {
            if ($a['overdue'] !== $b['overdue']) {
                return $a['overdue'] ? -1 : 1;
            }
            $dueA = $a['due'] ?? '9999-12-31';
            $dueB = $b['due'] ?? '9999-12-31';
            if ($dueA !== $dueB) {
                return strcmp($dueA, $dueB);
            }

            return (self::PRIORITY_RANK[$a['priority']] ?? 4) <=> (self::PRIORITY_RANK[$b['priority']] ?? 4);
        });

        return array_map(fn ($item) => $this->strip($item), $items);
    }

    public function myPermits(User $user): array
    {
        $companyIds = $this->stats->resolveCompanyIds(null);

        $permits = PermitToWork::query()
            ->whereIn('company_id', $companyIds)
            ->whereIn('status', self::OPEN_PERMIT_STATUSES)
            ->with('project:id,name')
            ->where(function ($query) use ($user) {
                $query->where('requested_by', $user->id);
                if ($user->employee_id) {
                    $query->orWhere('pic_employee_id', $user->employee_id);
                }
            })
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'approved' THEN 1 WHEN 'verified' THEN 2 WHEN 'submitted' THEN 3 ELSE 4 END")
            ->orderBy('start_datetime')
            ->limit(50)
            ->get();

        return $permits->map(fn (PermitToWork $permit) => $this->strip($this->item(
            kind: 'permit',
            title: $permit->workIdentity() ?: $permit->documentLabel(),
            subtitle: $permit->location,
            number: $permit->ptw_number,
            status: $permit->status,
            due: $permit->start_datetime,
            priority: null,
            route: 'permits-to-work.show',
            href: route('permits-to-work.show', $permit->id),
            user: $user,
            extra: [
                'role' => (int) $permit->requested_by === (int) $user->id ? 'requester' : 'pic',
                'end' => $permit->end_datetime?->toIso8601String(),
            ],
        )))->all();
    }

    private function item(
        string $kind,
        string $title,
        ?string $subtitle,
        ?string $number,
        ?string $status,
        mixed $due,
        ?string $priority,
        ?string $route,
        ?string $href,
        User $user,
        bool $overdue = false,
        mixed $sortAt = null,
        array $extra = [],
    ): array {
        $dueIso = $due instanceof Carbon ? $due->toIso8601String() : null;

        return [
            'kind' => $kind,
            'title' => $title,
            'subtitle' => $subtitle,
            'number' => $number,
            'status' => $status,
            'due' => $dueIso,
            'overdue' => $overdue,
            'priority' => $priority,
            // Offered only when the person could actually open it.
            'href' => $href && RouteReach::allows($user, $route) ? $href : null,
            '_sort' => $sortAt instanceof Carbon ? $sortAt->toIso8601String() : $dueIso,
        ] + $extra;
    }

    private function strip(array $item): array
    {
        unset($item['_sort']);

        return $item;
    }
}
