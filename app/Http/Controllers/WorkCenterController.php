<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\MaterialRequest;
use App\Models\Task;
use App\Services\WorkCenterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Work Center (v1.8.0). The single cross-department "what needs my
 * attention" surface -- see WorkCenterService for the actual queries.
 * Every item here links back into the module that actually owns the
 * record (Material Requests stay Logistics', Tasks stay wherever they
 * were created); this controller never renders module-specific UI of its
 * own, only a shaped index of pointers into it.
 */
class WorkCenterController extends Controller
{
    public function __construct(private readonly WorkCenterService $workCenter) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('WorkCenter/Index', [
            'approvals' => $this->workCenter->pendingApprovalsFor($user)
                ->map(fn (Approval $approval) => [
                    'id' => $approval->id,
                    'label' => $this->approvableLabel($approval),
                    'requester' => $approval->requester?->name,
                    'created_at' => $approval->created_at->diffForHumans(),
                    'url' => $this->approvableUrl($approval),
                ])
                ->values(),
            'tasks' => $this->workCenter->myTasksFor($user)
                ->map(fn (Task $task) => [
                    'id' => $task->id,
                    'task_number' => $task->task_number,
                    'title' => $task->title,
                    'priority' => $task->priority,
                    'status' => $task->status,
                    'due_date' => $task->due_date?->toDateString(),
                    'is_overdue' => $task->is_overdue,
                    'company' => $task->company?->name,
                    'url' => route('tasks.show', $task),
                ])
                ->values(),
            'alerts' => [
                'ppe' => [
                    'count' => $this->workCenter->ppeAlertCount(),
                    'url' => route('ppe.dashboard'),
                ],
            ],
        ]);
    }

    /**
     * One entry per approvable type that actually exists today -- add a
     * case here (and to approvableUrl() below) the day a second workflow
     * type (Permit To Work, Purchase Request, ...) starts using the
     * Approval Engine. Deliberately not a polymorphic
     * interface/displayLabel() method on every approvable model yet,
     * since Material Request is still the only real consumer -- see
     * config/workflow.php's own note on the same "only one consumer so
     * far" tradeoff.
     */
    private function approvableLabel(Approval $approval): string
    {
        return match (true) {
            $approval->approvable instanceof MaterialRequest => 'Material Request '.$approval->approvable->request_number,
            default => class_basename($approval->approvable_type).' #'.$approval->approvable_id,
        };
    }

    private function approvableUrl(Approval $approval): ?string
    {
        return match (true) {
            $approval->approvable instanceof MaterialRequest => route('material-requests.show', $approval->approvable),
            default => null,
        };
    }
}
