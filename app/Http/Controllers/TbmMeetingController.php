<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Project;
use App\Models\TbmMeeting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Milestone 4, Workstream B3 (Toolbox Meeting / TBM). */
class TbmMeetingController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $meetings = TbmMeeting::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->withCount('attendees')
            ->with('project:id,name', 'conductor:id,name')
            ->when($request->input('search'), fn ($q, $v) => $q->where('tbm_number', 'like', "%{$v}%")->orWhere('topic', 'like', "%{$v}%"))
            ->latest('meeting_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('TbmMeetings/Index', [
            'meetings' => $meetings,
            'filters' => $request->only('search'),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()->canManageHse(), 403);
        $tenantCompanyIds = Company::query()->pluck('id');

        return Inertia::render('TbmMeetings/Form', [
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'projects' => Project::whereIn('company_id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'employees' => Employee::whereIn('company_id', $tenantCompanyIds)->active()->orderBy('full_name')->get(['id', 'full_name']),
            'tbmNumber' => TbmMeeting::generateNumber(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);

        $tenantCompanyIds = Company::query()->pluck('id');
        $tenantProjectIds = Project::whereIn('company_id', $tenantCompanyIds)->pluck('id');
        $tenantEmployeeIds = Employee::whereIn('company_id', $tenantCompanyIds)->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'project_id' => ['nullable', Rule::in($tenantProjectIds)],
            'topic' => ['required', 'string', 'max:255'],
            'meeting_date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attendee_ids' => ['nullable', 'array'],
            'attendee_ids.*' => ['integer', Rule::in($tenantEmployeeIds)],
        ]);

        $attendeeIds = $data['attendee_ids'] ?? [];
        unset($data['attendee_ids']);

        $meeting = DB::transaction(function () use ($data, $attendeeIds, $request) {
            $meeting = TbmMeeting::create([
                ...$data,
                'tbm_number' => TbmMeeting::generateNumber(),
                'status' => TbmMeeting::STATUS_CONDUCTED,
                'conducted_by' => $request->user()->id,
            ]);
            $meeting->attendees()->sync($attendeeIds);

            return $meeting;
        });

        ActivityLog::record('created', "Recorded TBM {$meeting->tbm_number} -- {$meeting->topic}.", $meeting);

        return redirect()->route('tbm-meetings.show', $meeting)->with('flash', ['success' => 'TBM recorded.']);
    }

    public function show(TbmMeeting $tbmMeeting, Request $request): Response
    {
        abort_unless(Company::query()->pluck('id')->contains($tbmMeeting->company_id), 404);
        $tbmMeeting->load('company:id,name', 'project:id,name', 'conductor:id,name', 'attendees:id,full_name');

        $activities = ActivityLog::where('subject_type', TbmMeeting::class)
            ->where('subject_id', $tbmMeeting->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('TbmMeetings/Show', [
            'meeting' => $tbmMeeting,
            'activities' => $activities,
            'canManage' => $request->user()->canManageHse(),
        ]);
    }
}
