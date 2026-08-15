<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Services\CalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v1.11.0 (SaaS Finalization Pass, Part 4/5/6). ONE global calendar,
 * reachable from main navigation -- NOT a per-department calendar table.
 * This page is the full, unfiltered view of the ONE Calendar Engine
 * (`App\Services\CalendarService`); the Main Dashboard's Management
 * Calendar widget and each department Overview's Department Calendar
 * widget are narrower *views* over the same engine, not separate systems
 * -- see `CalendarService`'s own doc comment for the aggregation/filtering
 * logic shared by all three.
 *
 * v1.11.2 (Final Completion Pass, Part 2/3/5): added the
 * `is_management_event` ("Show on Management Calendar") flag and RBAC
 * around who may set it -- see `assertCanSetManagementFlag()`. CREATE/EDIT
 * of a manual event stays open to any authenticated tenant user (this is
 * still primarily a lightweight operational scheduling tool at that level),
 * but promoting an event onto the cross-department Management Calendar is
 * gated to Super Admin / HSE (isAdmin()) / Manager -- reusing the existing
 * role system, no new role concept introduced. DELETE remains restricted
 * to the event's own tenant (assertInCurrentTenant), matching every other
 * ownership check in this codebase.
 */
class CalendarController extends Controller
{
    public function __construct(private readonly CalendarService $calendar) {}

    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $start = $request->input('start') ? \Carbon\Carbon::parse($request->input('start')) : now()->startOfMonth()->subDays(7);
        $end = $request->input('end') ? \Carbon\Carbon::parse($request->input('end')) : now()->endOfMonth()->addDays(7);

        $events = $this->calendar->aggregate($tenantCompanyIds, $start, $end);

        return Inertia::render('Calendar/Index', [
            'events' => $events,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'eventTypes' => CalendarEvent::TYPES,
            'companies' => Company::whereIn('id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'can' => [
                'manage' => true, // any authenticated tenant user may create/edit their own manual events
                'markManagement' => $this->canSetManagementFlag($request->user()),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day' => ['boolean'],
            'event_type' => ['required', Rule::in(CalendarEvent::TYPES)],
            'department_key' => ['nullable', 'string', 'max:50'],
            'responsible_user_id' => ['nullable', 'exists:users,id'],
            'is_management_event' => ['boolean'],
        ]);

        if (! $this->canSetManagementFlag($request->user())) {
            $data['is_management_event'] = false;
        }

        $event = CalendarEvent::create([...$data, 'created_by' => $request->user()->id]);

        ActivityLog::record('created', "Created calendar event \"{$event->title}\".", $event);

        return back()->with('success', 'Event created.');
    }

    public function update(Request $request, CalendarEvent $calendarEvent): RedirectResponse
    {
        $this->assertInCurrentTenant($calendarEvent);

        $tenantCompanyIds = Company::query()->pluck('id');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'all_day' => ['boolean'],
            'event_type' => ['required', Rule::in(CalendarEvent::TYPES)],
            'department_key' => ['nullable', 'string', 'max:50'],
            'responsible_user_id' => ['nullable', 'exists:users,id'],
            'is_management_event' => ['boolean'],
        ]);

        // Only an authorized manager/admin may change the management-visibility
        // flag; anyone else's edit silently keeps whatever the flag already was
        // rather than erroring out on an otherwise-legitimate edit.
        if (! $this->canSetManagementFlag($request->user())) {
            $data['is_management_event'] = $calendarEvent->is_management_event;
        }

        $calendarEvent->update($data);

        return back()->with('success', 'Event updated.');
    }

    public function destroy(CalendarEvent $calendarEvent): RedirectResponse
    {
        $this->assertInCurrentTenant($calendarEvent);
        $calendarEvent->delete();

        return back()->with('success', 'Event removed.');
    }

    /** Super Admin / HSE (isAdmin()) / Manager may mark an event for the Management Calendar -- reuses the existing role system. */
    private function canSetManagementFlag($user): bool
    {
        return $user && ($user->isAdmin() || $user->isManager());
    }

    private function assertInCurrentTenant(CalendarEvent $event): void
    {
        abort_unless(Company::query()->pluck('id')->contains($event->company_id), 404);
    }
}
