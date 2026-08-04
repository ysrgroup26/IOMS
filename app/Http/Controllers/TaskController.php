<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Models\Company;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Universal Task Engine Foundation controller (v1.6.4). Standard Laravel
 * resource controller; all business logic is delegated to TaskService,
 * per the "business logic stays in the service layer" requirement.
 *
 * Authorization: uses the existing auth system, no new one. Any
 * authenticated user can view tasks and create new ones (a general-
 * purpose engine meant for future modules broadly). Editing/deleting/
 * reassigning a task is restricted to its creator, its assignee, or an
 * admin -- enforced inline here rather than via a dedicated Policy class,
 * consistent with this version's foundation-only scope.
 */
class TaskController extends Controller
{
    public function __construct(private readonly TaskService $tasks) {}

    public function index(Request $request): Response
    {
        $query = Task::query()->with('assignedUser:id,name', 'creator:id,name', 'company:id,name');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('task_number', 'like', "%{$search}%");
            });
        }

        $query->when($request->input('priority'), fn ($q, $v) => $q->where('priority', $v))
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('assigned_user_id'), fn ($q, $v) => $q->where('assigned_user_id', $v))
            ->when($request->input('due_date_from'), fn ($q, $v) => $q->whereDate('due_date', '>=', $v))
            ->when($request->input('due_date_to'), fn ($q, $v) => $q->whereDate('due_date', '<=', $v));

        $sort = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc');
        $allowedSorts = ['created_at', 'due_date', 'priority', 'status', 'title', 'task_number'];
        $query->orderBy(in_array($sort, $allowedSorts, true) ? $sort : 'created_at', $direction === 'asc' ? 'asc' : 'desc');

        $tasks = $query->paginate(20)->withQueryString();

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('search', 'priority', 'status', 'assigned_user_id', 'due_date_from', 'due_date_to', 'sort', 'direction'),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Tasks/Form', [
            'task' => null,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
        ]);
    }

    public function store(StoreTaskRequest $request): RedirectResponse
    {
        $task = $this->tasks->createTask($request->validated(), $request->user()->id);

        return redirect()->route('tasks.show', $task)->with('success', "Task {$task->task_number} created.");
    }

    public function show(Task $task): Response
    {
        $task->load('assignedUser:id,name,email', 'creator:id,name', 'company:id,name');

        return Inertia::render('Tasks/Show', [
            'task' => $task,
            'can' => ['manage' => $this->canManage($task)],
        ]);
    }

    public function edit(Task $task): Response
    {
        abort_unless($this->canManage($task), 403);

        return Inertia::render('Tasks/Form', [
            'task' => $task,
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'statuses' => Task::STATUSES,
            'priorities' => Task::PRIORITIES,
        ]);
    }

    public function update(UpdateTaskRequest $request, Task $task): RedirectResponse
    {
        abort_unless($this->canManage($task), 403);

        $this->tasks->updateTask($task, $request->validated());

        return redirect()->route('tasks.show', $task)->with('success', 'Task updated.');
    }

    public function destroy(Task $task): RedirectResponse
    {
        abort_unless($this->canManage($task), 403);

        $this->tasks->deleteTask($task);

        return redirect()->route('tasks.index')->with('success', 'Task removed.');
    }

    private function canManage(Task $task): bool
    {
        $user = request()->user();

        return $user->isAdmin()
            || $task->created_by === $user->id
            || $task->assigned_user_id === $user->id;
    }
}
