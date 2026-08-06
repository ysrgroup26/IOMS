<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 3 (Activity Center, Task #50). The cross-record, filterable
 * counterpart to `App\Components\shared\ActivityTimeline` (which only
 * ever shows one record's own history) -- reads the exact same
 * `ActivityLog` table every other module already writes to via
 * `ActivityLog::record()`, genuinely nothing new to log, just a real way
 * to browse everything that's already being recorded.
 */
class ActivityCenterController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['user_id', 'company_id', 'department_id', 'module', 'date_from', 'date_to']);

        $activities = ActivityLog::query()
            ->with(['user:id,name', 'company:id,name'])
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['company_id'] ?? null, fn ($q, $v) => $q->where('company_id', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['module'] ?? null, fn ($q, $v) => $q->where('module', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('ActivityCenter/Index', [
            'activities' => $activities,
            'filters' => $filters,
            'options' => [
                'users' => User::orderBy('name')->get(['id', 'name']),
                'companies' => Company::orderBy('name')->get(['id', 'name']),
                'departments' => Department::orderBy('name')->get(['id', 'name']),
                'modules' => ActivityLog::query()->whereNotNull('module')->distinct()->orderBy('module')->pluck('module'),
            ],
        ]);
    }
}
