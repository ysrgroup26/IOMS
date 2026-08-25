<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Approval;
use App\Models\MaterialRequest;
use App\Models\Notification;
use App\Models\Task;
use App\Services\WorkCenterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Work Center (v1.8.0, extended Milestone 3 Task #63 into the
 * "My Workspace" personal dashboard). The single cross-department "what
 * needs my attention" surface -- see WorkCenterService for the
 * approvals/tasks/alerts queries. Every item here links back into the
 * module that actually owns the record (Material Requests stay
 * Logistics', Tasks stay wherever they were created); this controller
 * never renders module-specific UI of its own, only a shaped index of
 * pointers into it.
 *
 * Task #63 added the Notifications and Recent Activity widgets below,
 * both real data (no new tables/services -- reuses the existing
 * Notification and ActivityLog models exactly as the Notification
 * Center / Activity Center already query them), completing the "My
 * Workspace" tier of the Enterprise Dashboard epic without duplicating
 * this page as a separate route.
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
            // v2.2.0 (IOMS OS Ecosystem pass, Part 6 -- My Tasks / Action
            // Center): real cross-department alerts (CAPA due, PTW
            // pending, stock alert, maintenance due, PR/PO pending),
            // gated per-item by the viewing user's own capabilities --
            // see WorkCenterService::actionAlertsFor()'s own doc comment.
            'actionAlerts' => $this->workCenter->actionAlertsFor($user),
            'notifications' => [
                'unread_count' => Notification::where('user_id', $user->id)->unread()->count(),
                'recent' => Notification::where('user_id', $user->id)
                    ->latest('created_at')
                    ->limit(5)
                    ->get()
                    ->map(fn (Notification $n) => [
                        'id' => $n->id,
                        'category' => $n->category,
                        'title' => $n->title,
                        'url' => $n->url,
                        'is_read' => $n->isRead(),
                        'created_at' => $n->created_at->diffForHumans(),
                    ]),
            ],
            'recentActivity' => ActivityLog::where('user_id', $user->id)
                ->latest('created_at')
                ->limit(8)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'module' => $log->module,
                    'created_at' => $log->created_at->diffForHumans(),
                ]),
            // v2.2.0: moved to WorkCenterService::quickActionsFor() so the
            // Main Dashboard can reuse the exact same list -- see that
            // method's own doc comment.
            'quickActions' => $this->workCenter->quickActionsFor($user),
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
