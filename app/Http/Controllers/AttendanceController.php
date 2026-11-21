<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RestrictDepartmentAccess;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSite;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Services\AttendanceService;
use App\Services\DashboardStatsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.146.0 -- Field Attendance, the HRD side: the daily register with
 * corrections and overtime approval, the crew roll call, and the site /
 * location settings. Route prefix `attendance` belongs to People / HRD
 * (Business). Every action authorizes itself here, per CONVENTIONS.md --
 * no `role:` route group.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly DashboardStatsService $dashboardStats,
    ) {}

    private function can(Request $request): array
    {
        $u = $request->user();

        return [
            'manage' => $u->canManageAttendance(),
            'approve_overtime' => $u->canApproveOvertime(),
            'crew' => $u->canTakeCrewAttendance() && RestrictDepartmentAccess::allows($u, 'attendance.crew'),
        ];
    }

    private function date(Request $request): Carbon
    {
        $v = (string) $request->input('date');
        $today = $this->attendance->today();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return $today;
        }

        return $v > $today->toDateString() ? $today : Carbon::parse($v);
    }

    public function index(Request $request): Response
    {
        $u = $request->user();
        abort_unless($u->canManageAttendance() || $u->canApproveOvertime() || $u->canTakeCrewAttendance(), 403);

        $companyId = $request->integer('company_id') ?: null;
        $companyIds = $this->dashboardStats->resolveCompanyIds($companyId);
        $date = $this->date($request);

        $pending = AttendanceRecord::query()->with('employee:id,full_name,employee_id')
            ->whereIn('company_id', $companyIds)
            ->where('overtime_status', AttendanceRecord::OVERTIME_PENDING)
            ->whereDate('work_date', '>=', $this->attendance->today()->subDays(30)->toDateString())
            ->orderBy('work_date')->limit(100)->get()
            ->map(fn (AttendanceRecord $r) => [
                'id' => $r->id,
                'name' => $r->employee?->full_name,
                'date' => $r->work_date->toDateString(),
                'overtime_minutes' => $r->overtime_minutes,
                'worked_minutes' => $r->worked_minutes,
            ]);

        return Inertia::render('Attendance/Index', [
            'date' => $date->toDateString(),
            'today' => $this->attendance->today()->toDateString(),
            'rows' => $this->attendance->register($companyIds, $date),
            'summary' => $this->attendance->summary($companyIds, $date),
            'pendingOvertime' => $pending,
            'employees' => $u->canManageAttendance()
                ? Employee::query()->whereIn('employees.company_id', $companyIds)->active()->orderedForDisplay()
                    ->get(['employees.id', 'employees.full_name', 'employees.employee_id'])
                : [],
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => ['company_id' => $companyId, 'date' => $date->toDateString()],
            'statuses' => AttendanceRecord::STATUSES,
            'can' => $this->can($request),
        ]);
    }

    public function correct(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403);
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'in:'.implode(',', AttendanceRecord::STATUSES)],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        // Defence in depth on top of the company scope: only this tenant's own operating units.
        abort_unless(in_array((int) $employee->company_id, $this->dashboardStats->resolveCompanyIds(null), true), 403);
        $date = Carbon::parse($data['work_date']);
        abort_if($data['work_date'] > $this->attendance->today()->toDateString(), 422, 'Tanggal koreksi tidak boleh di masa depan.');

        $this->attendance->correct($request->user(), $employee, $date, $data, $data['reason']);

        return back()->with('success', 'Absensi '.$employee->full_name.' dikoreksi dan tercatat di riwayat aktivitas.');
    }

    public function decideOvertime(Request $request, AttendanceRecord $record): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $this->attendance->decideOvertime($request->user(), $record, (bool) $data['approve']);

        return back()->with('success', $data['approve'] ? 'Lembur disetujui dan masuk Man-Hour.' : 'Lembur ditolak.');
    }

    public function crew(Request $request): Response
    {
        abort_unless($request->user()->canTakeCrewAttendance(), 403);
        $companyId = $request->integer('company_id') ?: null;
        $companyIds = $this->dashboardStats->resolveCompanyIds($companyId);
        $date = $this->date($request);
        $site = (string) $request->input('site', '');

        $rows = $this->attendance->register($companyIds, $date)->where('scheduled', true);
        $sites = $rows->pluck('site_name')->filter()->unique()->sort()->values();
        if ($site !== '') {
            $rows = $rows->where('site_name', $site);
        }

        return Inertia::render('Attendance/Crew', [
            'date' => $date->toDateString(),
            'today' => $this->attendance->today()->toDateString(),
            'rows' => $rows->values(),
            'sites' => $sites,
            'filters' => ['company_id' => $companyId, 'date' => $date->toDateString(), 'site' => $site],
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function crewStore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'entries' => ['required', 'array', 'max:500'],
            'entries.*' => ['required', 'in:'.implode(',', AttendanceService::CREW_STATUSES)],
            'company_id' => ['nullable', 'integer'],
        ]);
        $companyIds = $this->dashboardStats->resolveCompanyIds(isset($data['company_id']) ? (int) $data['company_id'] : null);
        $n = $this->attendance->crewRollCall($request->user(), Carbon::parse($data['work_date']), $data['entries'], $companyIds);

        return back()->with('success', "Absen regu tersimpan untuk {$n} orang. Absen mandiri pekerja tidak ditimpa.");
    }

    public function settings(Request $request): Response
    {
        abort_unless($request->user()->canManageAttendance(), 403);
        $companyIds = $this->dashboardStats->resolveCompanyIds(null);

        return Inertia::render('Attendance/Settings', [
            'sites' => AttendanceSite::query()->whereIn('company_id', $companyIds)->orderBy('name')
                ->get(['id', 'company_id', 'name', 'latitude', 'longitude', 'radius_m', 'is_active']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'settings' => [
                'require_location' => $this->attendance->requiresLocation(),
                'selfie' => $this->attendance->selfieEnabled(),
            ],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403);
        $data = $request->validate([
            'require_location' => ['required', 'boolean'],
            'selfie' => ['required', 'boolean'],
        ]);
        CompanySetting::set(AttendanceService::SETTING_REQUIRE_LOCATION, $data['require_location'] ? '1' : '0');
        CompanySetting::set(AttendanceService::SETTING_SELFIE, $data['selfie'] ? '1' : '0');

        return back()->with('success', 'Pengaturan absensi disimpan.');
    }

    public function storeSite(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403);
        $data = $this->siteData($request);
        AttendanceSite::create($data);

        return back()->with('success', 'Lokasi '.$data['name'].' ditambahkan.');
    }

    public function updateSite(Request $request, AttendanceSite $site): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403);
        $site->update($this->siteData($request));

        return back()->with('success', 'Lokasi '.$site->name.' diperbarui.');
    }

    public function destroySite(Request $request, AttendanceSite $site): RedirectResponse
    {
        abort_unless($request->user()->canManageAttendance(), 403);
        $site->update(['is_active' => false]);

        return back()->with('success', 'Lokasi '.$site->name.' dinonaktifkan. Riwayat absensinya tetap tersimpan.');
    }

    private function siteData(Request $request): array
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'between:50,5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        abort_unless(in_array((int) $data['company_id'], $this->dashboardStats->resolveCompanyIds(null), true), 422);

        return $data + ['is_active' => true];
    }
}
