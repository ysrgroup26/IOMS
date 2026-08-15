<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\LeaveRequest;
use App\Models\Milestone;
use App\Models\PermitToWork;
use App\Models\TbmMeeting;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * v1.11.2 (Final Completion Pass, Part 2). The single "IOMS Calendar
 * Engine" -- extracted out of CalendarController (which owned this logic
 * exclusively before this pass) so the Main Dashboard's Management Calendar
 * widget and each department Overview's Department Calendar widget can
 * reuse the exact same event aggregation instead of re-querying the same
 * five source modules a second and third time. There is still only ONE
 * calendar events table (`calendar_events`, manual events) and ONE set of
 * virtual-event providers -- this class does not introduce a second engine,
 * it names the one that already existed so three call sites (full Calendar
 * page, Dashboard widget, department widgets) can share it.
 *
 * Two derived views on top of the same aggregate, per the spec's explicit
 * "Department Calendar vs Management Calendar" split:
 *
 * - managementEvents(): manual events explicitly flagged
 *   `is_management_event` (an authorized manager/admin opted them in --
 *   see CalendarController::assertCanSetManagementFlag()) UNION a small,
 *   fixed set of virtual sources that are inherently cross-department
 *   significant regardless of any flag (PTW, Milestone deadlines) --
 *   the same two sources the pre-existing Dashboard widget already used,
 *   kept rather than narrowed to avoid silently regressing what was
 *   already shown, and documented here as a deliberate policy rather than
 *   an oversight.
 * - departmentEvents($departmentKey): every event (manual or virtual)
 *   already carrying that department's key -- reuses the exact
 *   `department_key` value each virtual provider already stamped in
 *   CalendarController's DTOs (see config/departments.php for the same
 *   key vocabulary used by RestrictDepartmentAccess).
 *
 * Tenant safety: every query here takes an already-tenant-scoped
 * `$companyIds` list from the caller -- this class never resolves tenant
 * scope itself. It accepts `Collection|array` (not a strict `Collection`)
 * because this codebase has TWO equally-established, equally-correct
 * tenant-scoping patterns and this class sits downstream of both:
 * `Company::query()->pluck('id')` (a `Collection`, used by
 * `CalendarController` and most other tenant-scoped controllers) and
 * `DashboardStatsService::resolveCompanyIds()` (a plain `array` --
 * returns `[$companyId]` when a single company is selected, or
 * `Company::query()->pluck('id')->all()` otherwise -- used by
 * `DashboardController` and every department dashboard controller, ~90
 * queries across those files that all already rely on Eloquent's
 * `whereIn()` accepting either type natively). A production TypeError
 * (v1.11.2.4) proved the strict `Collection` hint was wrong, not the
 * callers: every dashboard controller passing its own already-correct
 * `resolveCompanyIds()` array crashed here. Every internal use of
 * `$companyIds` in this class is `whereIn('company_id', $companyIds)` (or
 * `whereHas(...)->whereIn(...)`) -- confirmed by inspection, never a
 * Collection-only method call on `$companyIds` itself -- so widening the
 * type is a pure contract fix with no behavior change either way.
 */
class CalendarService
{
    /** Virtual sources always treated as management-relevant, regardless of any per-row flag (they have none). */
    private const ALWAYS_MANAGEMENT_SOURCES = ['permit-to-work', 'milestone'];

    public function aggregate(Collection|array $companyIds, $start, $end): Collection
    {
        return collect()
            ->merge($this->manualEvents($companyIds, $start, $end))
            ->merge($this->provideLeave($companyIds, $start, $end))
            ->merge($this->providePermitToWork($companyIds, $start, $end))
            ->merge($this->provideTbm($companyIds, $start, $end))
            ->merge($this->provideMilestones($companyIds, $start, $end))
            ->merge($this->provideWorkOrders($companyIds, $start, $end))
            ->sortBy('start')
            ->values();
    }

    public function managementEvents(Collection|array $companyIds, int $limit = 8, int $days = 14): array
    {
        $start = now()->startOfDay();
        $end = now()->addDays($days)->endOfDay();

        return $this->aggregate($companyIds, $start, $end)
            ->filter(fn (array $e) => ($e['source'] === 'manual' && ! empty($e['is_management_event']))
                || in_array($e['source'], self::ALWAYS_MANAGEMENT_SOURCES, true))
            ->sortBy('start')
            ->take($limit)
            ->values()
            ->all();
    }

    public function departmentEvents(Collection|array $companyIds, string $departmentKey, int $limit = 6, int $days = 21): array
    {
        $start = now()->startOfDay();
        $end = now()->addDays($days)->endOfDay();

        return $this->aggregate($companyIds, $start, $end)
            ->filter(fn (array $e) => $e['department_key'] === $departmentKey)
            ->sortBy('start')
            ->take($limit)
            ->values()
            ->all();
    }

    private function manualEvents(Collection|array $companyIds, $start, $end): Collection
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
                'is_management_event' => (bool) $e->is_management_event,
            ]);
    }

    private function provideLeave(Collection|array $companyIds, $start, $end): Collection
    {
        return LeaveRequest::whereIn('company_id', $companyIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereBetween('start_date', [$start, $end])
            ->with('employee:id,full_name')
            ->get()
            ->map(fn ($l) => $this->dto('leave', 'leave-request', $l->id, "Leave: {$l->employee?->full_name}", $l->start_date, $l->end_date, true, $l->status, 'hr', $l->employee?->full_name, null, $l));
    }

    private function providePermitToWork(Collection|array $companyIds, $start, $end): Collection
    {
        return PermitToWork::whereIn('company_id', $companyIds)
            ->whereIn('status', ['approved', 'active'])
            ->whereBetween('start_datetime', [$start, $end])
            ->get()
            ->map(fn ($p) => $this->dto('deadline', 'permit-to-work', $p->id, "PTW: {$p->ptw_number}", $p->start_datetime, $p->end_datetime, false, $p->status, 'hse', null, route('permits-to-work.show', $p->id), $p));
    }

    private function provideTbm(Collection|array $companyIds, $start, $end): Collection
    {
        return TbmMeeting::whereIn('company_id', $companyIds)
            ->whereBetween('meeting_date', [$start, $end])
            ->get()
            ->map(fn ($t) => $this->dto('meeting', 'tbm-meeting', $t->id, "TBM: {$t->topic}", $t->meeting_date, null, true, $t->status, 'hse', null, route('tbm-meetings.show', $t->id), $t));
    }

    private function provideMilestones(Collection|array $companyIds, $start, $end): Collection
    {
        return Milestone::whereHas('project', fn ($q) => $q->whereIn('company_id', $companyIds))
            ->whereBetween('target_date', [$start, $end])
            ->with('project:id,company_id,name')
            ->get()
            ->map(fn ($m) => $this->dto('deadline', 'milestone', $m->id, "Milestone: {$m->title}", $m->target_date, null, true, $m->status, 'project-management', null, null, $m->project));
    }

    private function provideWorkOrders(Collection|array $companyIds, $start, $end): Collection
    {
        return WorkOrder::whereIn('company_id', $companyIds)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereBetween('planned_date', [$start, $end])
            ->with('asset:id,name')
            ->get()
            ->map(fn ($w) => $this->dto('deadline', 'work-order', $w->id, "WO: {$w->asset?->name}", $w->planned_date, null, true, $w->status, 'maintenance', null, route('work-orders.show', $w->id), $w));
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
            'editable' => false,
            'company_id' => $raw?->company_id,
            'is_management_event' => in_array($source, self::ALWAYS_MANAGEMENT_SOURCES, true),
        ];
    }
}
