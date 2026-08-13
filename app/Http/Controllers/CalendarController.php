<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\Milestone;
use App\Models\PermitToWork;
use App\Models\TbmMeeting;
use App\Models\WorkOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v1.11.0 (SaaS Finalization Pass, Part 4/5/6). ONE global calendar,
 * reachable from main navigation -- NOT a per-department calendar.
 * Aggregates manual `CalendarEvent` rows with a small set of READ-ONLY
 * "virtual" events computed live from existing due-date fields already on
 * other modules (see each `provide*()` method below) -- a Unified Event
 * DTO (a plain array shape), never a duplicated events table per module.
 *
 * Deliberately does NOT surface every possible date in the system --
 * audited and picked only sources with a real, unambiguous single "this
 * is when it happens" date: Leave (date range), Permit To Work (date
 * range), TBM (meeting date), Milestone (target date), Work Order
 * (planned date). Sources with no single obvious date (e.g. Gas Test --
 * a reading, not a scheduled event) are deliberately excluded rather than
 * forced in.
 *
 * Tenant/department safety: every provider is scoped through the exact
 * same `Company::query()->pluck('id')` tenant-safe pattern this codebase
 * already uses everywhere, applied a second time here rather than
 * reimplemented -- and each virtual event's `department_key` mirrors
 * that module's own owning department (see config/departments.php),
 * so a future department-scoped calendar filter has real data to filter
 * on without this controller needing to know about RBAC itself.
 */
class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $start = $request->input('start') ? \Carbon\Carbon::parse($request->input('start')) : now()->startOfMonth()->subDays(7);
        $end = $request->input('end') ? \Carbon\Carbon::parse($request->input('end')) : now()->endOfMonth()->addDays(7);

        $events = collect()
            ->merge($this->manualEvents($tenantCompanyIds, $start, $end))
            ->merge($this->provideLeave($tenantCompanyIds, $start, $end))
            ->merge($this->providePermitToWork($tenantCompanyIds, $start, $end))
            ->merge($this->provideTbm($tenantCompanyIds, $start, $end))
            ->merge($this->provideMilestones($tenantCompanyIds, $start, $end))
            ->merge($this->provideWorkOrders($tenantCompanyIds, $start, $end))
            ->sortBy('start')
            ->values();

        return Inertia::render('Calendar/Index', [
            'events' => $events,
            'range' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'eventTypes' => CalendarEvent::TYPES,
            'companies' => Company::whereIn('id', $tenantCompanyIds)->orderBy('name')->get(['id', 'name']),
            'can' => ['manage' => true], // any authenticated tenant user may create a manual event; module events are always read-only
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
        ]);

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
        ]);

        $calendarEvent->update($data);

        return back()->with('success', 'Event updated.');
    }

    public function destroy(CalendarEvent $calendarEvent): RedirectResponse
    {
        $this->assertInCurrentTenant($calendarEvent);
        $calendarEvent->delete();

        return back()->with('success', 'Event removed.');
    }

    private function assertInCurrentTenant(CalendarEvent $event): void
    {
        abort_unless(Company::query()->pluck('id')->contains($event->company_id), 404);
    }

    private function dto(string $type, string $source, $sourceId, string $title, $start, $end = null, bool $allDay = false, ?string $status = null, ?string $departmentKey = null, ?string $responsible = null, ?string $url = null, $raw = null): array
    {
        return [
            'id' => "{$source}-{$sourceId}",
            'title' => $title,
            'start' => optional($start)->toIso8601String(),
            'end' => optional($end)->toIso8601String(),
            'all_day' => $allDay,
            'event_type' => $type,
            'source' => $source,
            'source_id' => $sourceId,
            'status' => $status,
            'department_key' => $departmentKey,
            'responsible' => $responsible,
            'url' => $url,
            'editable' => $source === 'manual',
            'company_id' => $raw?->company_id,
        ];
    }

    private function manualEvents($companyIds, $start, $end)
    {
        return CalendarEvent::whereIn('company_id', $companyIds)
            ->whereBetween('start_at', [$start, $end])
            ->with('responsible:id,name')
            ->get()
            ->map(fn (CalendarEvent $e) => [
                'id' => "manual-{$e->id}",
                'title' => $e->title,
                'start' => $e->start_at->toIso8601String(),
                'end' => $e->end_at?->toIso8601String(),
                'all_day' => $e->all_day,
                'event_type' => $e->event_type,
                'source' => 'manual',
                'source_id' => $e->id,
                'status' => null,
                'department_key' => $e->department_key,
                'responsible' => $e->responsible?->name,
                'url' => null,
                'editable' => true,
                'company_id' => $e->company_id,
                'description' => $e->description,
            ]);
    }

    private function provideLeave($companyIds, $start, $end)
    {
        return LeaveRequest::whereIn('company_id', $companyIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereBetween('start_date', [$start, $end])
            ->with('employee:id,full_name')
            ->get()
            ->map(fn ($l) => $this->dto('leave', 'leave-request', $l->id, "Leave: {$l->employee?->full_name}", $l->start_date, $l->end_date, true, $l->status, 'hr', $l->employee?->full_name, null, $l));
    }

    private function providePermitToWork($companyIds, $start, $end)
    {
        return PermitToWork::whereIn('company_id', $companyIds)
            ->whereIn('status', ['approved', 'active'])
            ->whereBetween('start_datetime', [$start, $end])
            ->get()
            ->map(fn ($p) => $this->dto('deadline', 'permit-to-work', $p->id, "PTW: {$p->ptw_number}", $p->start_datetime, $p->end_datetime, false, $p->status, 'hse', null, route('permits-to-work.show', $p->id), $p));
    }

    private function provideTbm($companyIds, $start, $end)
    {
        return TbmMeeting::whereIn('company_id', $companyIds)
            ->whereBetween('meeting_date', [$start, $end])
            ->get()
            ->map(fn ($t) => $this->dto('meeting', 'tbm-meeting', $t->id, "TBM: {$t->topic}", $t->meeting_date, null, true, $t->status, 'hse', null, route('tbm-meetings.show', $t->id), $t));
    }

    private function provideMilestones($companyIds, $start, $end)
    {
        return Milestone::whereHas('project', fn ($q) => $q->whereIn('company_id', $companyIds))
            ->whereBetween('target_date', [$start, $end])
            ->with('project:id,company_id,name')
            ->get()
            ->map(fn ($m) => $this->dto('deadline', 'milestone', $m->id, "Milestone: {$m->title}", $m->target_date, null, true, $m->status, 'project-management', null, null, $m->project));
    }

    private function provideWorkOrders($companyIds, $start, $end)
    {
        return WorkOrder::whereIn('company_id', $companyIds)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereBetween('planned_date', [$start, $end])
            ->with('asset:id,name')
            ->get()
            ->map(fn ($w) => $this->dto('deadline', 'work-order', $w->id, "WO: {$w->asset?->name}", $w->planned_date, null, true, $w->status, 'maintenance', null, route('work-orders.show', $w->id), $w));
    }
}
