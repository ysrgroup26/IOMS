<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSite;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeRoster;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveRequest;
use App\Models\ManHourLog;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v2.146.0 -- FIELD ATTENDANCE (owner-approved design, 21 Nov 2026).
 *
 * Three ways in, one record per employee per work date:
 *   - the worker's own check-in / check-out on My Work (`self`),
 *   - a foreman's crew roll call from the day's roster (`crew`),
 *   - an HRD correction with a mandatory reason (`hr`).
 *
 * Times come from the server, never the phone. Shift times are local wall
 * clock, so "today" and lateness are computed in the display timezone
 * (config ioms.display_timezone) while timestamps are stored in UTC.
 *
 * Man-Hour is filled from attendance: worked minutes minus the shift's
 * break. Minutes beyond the shift's working hours (30 or more) are
 * overtime, held as `pending` and only added to Man-Hour once a
 * supervisor approves them. A Man-Hour row that attendance did not create
 * (manual entry, e.g. a contractor without an account) is never
 * overwritten.
 */
class AttendanceService
{
    public const LATE_GRACE_MINUTES = 15;

    public const OVERTIME_MIN_MINUTES = 30;

    /** Marker on Man-Hour rows produced by attendance. */
    public const MAN_HOUR_NOTE = 'Dari absensi';

    public const SETTING_REQUIRE_LOCATION = 'attendance.require_location';

    public const SETTING_SELFIE = 'attendance.selfie';

    // ---------------------------------------------------------------- settings

    public function requiresLocation(): bool
    {
        return (bool) CompanySetting::get(self::SETTING_REQUIRE_LOCATION, true);
    }

    public function selfieEnabled(): bool
    {
        return (bool) CompanySetting::get(self::SETTING_SELFIE, false);
    }

    public function tz(): string
    {
        return (string) config('ioms.display_timezone', 'Asia/Jakarta');
    }

    public function today(): Carbon
    {
        return Carbon::now($this->tz())->startOfDay();
    }

    // ---------------------------------------------------------------- schedule

    /**
     * Who is scheduled on a date, keyed by employee id:
     * ['employee' => Employee, 'shift' => ?Shift, 'site_name' => ?string, 'project_id' => ?int].
     * A roster entry wins over a plain shift assignment; a roster whose
     * pattern puts the date on an OFF day means not scheduled.
     */
    public function scheduled(array $companyIds, CarbonInterface $date): Collection
    {
        $d = $date->toDateString();
        $out = collect();

        $rosters = EmployeeRoster::query()
            ->with(['employee', 'shift', 'rosterPattern'])
            ->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds)->active())
            ->where('status', EmployeeRoster::STATUS_ACTIVE)
            ->whereDate('start_date', '<=', $d)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $d))
            ->get();

        $offByRoster = [];
        foreach ($rosters as $r) {
            if ($r->dutyTypeOn(Carbon::parse($d)) === 'off') {
                $offByRoster[$r->employee_id] = true;

                continue;
            }
            $out[$r->employee_id] = [
                'employee' => $r->employee,
                'shift' => $r->shift,
                'site_name' => $r->site_name,
                'project_id' => $r->project_id,
            ];
        }

        $assignments = EmployeeShiftAssignment::query()
            ->with(['employee', 'shift'])
            ->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds)->active())
            ->where('status', 'active')
            ->whereDate('effective_date', '<=', $d)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $d))
            ->get();

        foreach ($assignments as $a) {
            if (isset($out[$a->employee_id]) || isset($offByRoster[$a->employee_id])) {
                continue;
            }
            $out[$a->employee_id] = ['employee' => $a->employee, 'shift' => $a->shift, 'site_name' => null, 'project_id' => null];
        }

        return $out;
    }

    public function scheduleFor(Employee $employee, CarbonInterface $date): ?array
    {
        return $this->scheduled([$employee->company_id], $date)->get($employee->id);
    }

    /** Employee ids on approved leave on a date. */
    public function onLeave(array $employeeIds, CarbonInterface $date): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $d = $date->toDateString();

        return LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $d)
            ->whereDate('end_date', '>=', $d)
            ->pluck('employee_id')->unique()->values()->all();
    }

    // ---------------------------------------------------------------- self check-in / out

    public function checkIn(User $user, ?float $lat, ?float $lng): AttendanceRecord
    {
        $employee = $this->employeeOf($user);
        $date = $this->today();

        $existing = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $date->toDateString())->first();
        if ($existing && $existing->check_in_at) {
            throw ValidationException::withMessages(['attendance' => 'Anda sudah absen masuk hari ini pukul '
                .$existing->check_in_at->copy()->setTimezone($this->tz())->format('H.i').'.']);
        }
        if ($existing && $existing->status === AttendanceRecord::STATUS_LEAVE
            || in_array($employee->id, $this->onLeave([$employee->id], $date), true)) {
            throw ValidationException::withMessages(['attendance' => 'Anda tercatat cuti hari ini. Hubungi HRD bila ternyata bekerja.']);
        }

        [$siteId, $distance] = $this->checkLocation($employee, $lat, $lng);

        $schedule = $this->scheduleFor($employee, $date);
        $shift = $schedule['shift'] ?? null;
        $now = Carbon::now();
        $status = AttendanceRecord::STATUS_PRESENT;
        if ($shift && $shift->start_time) {
            $start = Carbon::parse($date->toDateString().' '.$shift->start_time, $this->tz());
            if ($now->gt($start->copy()->addMinutes(self::LATE_GRACE_MINUTES))) {
                $status = AttendanceRecord::STATUS_LATE;
            }
        }

        $record = $existing ?? new AttendanceRecord(['employee_id' => $employee->id, 'work_date' => $date->toDateString()]);
        $record->fill([
            'company_id' => $employee->company_id,
            'shift_id' => $shift?->id,
            'status' => $status,
            'check_in_at' => $now,
            'check_in_latitude' => $lat,
            'check_in_longitude' => $lng,
            'site_id' => $siteId,
            'distance_m' => $distance,
            'source' => AttendanceRecord::SOURCE_SELF,
            'recorded_by' => $user->id,
        ])->save();

        return $record;
    }

    public function checkOut(User $user, ?float $lat, ?float $lng): AttendanceRecord
    {
        $employee = $this->employeeOf($user);
        $today = $this->today();

        // An open record from today, or from yesterday for a night shift that crossed midnight.
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereDate('work_date', $today->toDateString())->orWhereDate('work_date', $today->copy()->subDay()->toDateString()))
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->orderByDesc('work_date')
            ->first();

        if (! $record) {
            $done = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $today->toDateString())->whereNotNull('check_out_at')->exists();
            throw ValidationException::withMessages(['attendance' => $done
                ? 'Anda sudah absen pulang hari ini.'
                : 'Belum ada absen masuk hari ini. Absen masuk dulu, atau minta HRD mengoreksi bila lupa.']);
        }
        if ($record->check_in_at->lt(Carbon::now()->subHours(20))) {
            throw ValidationException::withMessages(['attendance' => 'Absen masuk terakhir sudah lebih dari 20 jam. Minta HRD mengoreksi jam pulangnya.']);
        }

        $this->checkLocation($employee, $lat, $lng);

        $record->fill([
            'check_out_at' => Carbon::now(),
            'check_out_latitude' => $lat,
            'check_out_longitude' => $lng,
        ]);
        $this->computeFromTimes($record);
        $record->save();
        $this->syncManHour($record, $user);

        return $record->fresh();
    }

    /** [siteId, distanceMetres] when a site applies; throws when the person is outside every site. */
    public function checkLocation(Employee $employee, ?float $lat, ?float $lng): array
    {
        $sites = AttendanceSite::query()->where('company_id', $employee->company_id)->where('is_active', true)->get();
        if (! $this->requiresLocation() || $sites->isEmpty()) {
            return [null, null];
        }
        if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
            throw ValidationException::withMessages(['attendance' => 'Lokasi diperlukan untuk absen. Izinkan akses lokasi di browser, lalu coba lagi.']);
        }

        $nearest = $sites->map(fn (AttendanceSite $s) => [
            'site' => $s,
            'distance' => AttendanceSite::distanceMetres($lat, $lng, $s->latitude, $s->longitude),
        ])->sortBy('distance')->first();

        if ($nearest['distance'] > $nearest['site']->radius_m) {
            throw ValidationException::withMessages(['attendance' => sprintf(
                'Anda berada sekitar %s m dari %s, di luar batas %s m. Absen dari area kerja, atau minta foreman mengabsenkan regu.',
                number_format($nearest['distance'], 0, ',', '.'), $nearest['site']->name, number_format($nearest['site']->radius_m, 0, ',', '.')
            )]);
        }

        return [$nearest['site']->id, (int) round($nearest['distance'])];
    }

    // ---------------------------------------------------------------- crew roll call

    /**
     * @param  array<int,string>  $entries  employee_id => status
     * @return int number of records written
     */
    public function crewRollCall(User $user, CarbonInterface $date, array $entries, array $companyIds): int
    {
        if (! $user->canTakeCrewAttendance()) {
            abort(403);
        }
        $d = Carbon::parse($date->toDateString());
        $today = $this->today()->toDateString();
        if ($d->toDateString() > $today || $d->toDateString() < $this->today()->subDays(7)->toDateString()) {
            throw ValidationException::withMessages(['work_date' => 'Absen regu hanya untuk hari ini sampai 7 hari ke belakang.']);
        }

        $schedule = $this->scheduled($companyIds, $d);
        $written = 0;

        DB::transaction(function () use ($entries, $companyIds, $d, $schedule, $user, &$written) {
            foreach ($entries as $employeeId => $status) {
                if (! in_array($status, AttendanceRecord::STATUSES, true)) {
                    continue;
                }
                $employee = Employee::query()->whereIn('company_id', $companyIds)->find((int) $employeeId);
                if (! $employee) {
                    continue;
                }
                $record = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $d->toDateString())->first();
                // A worker's own check-in is the stronger record; the roll call never overwrites it.
                if ($record && $record->source === AttendanceRecord::SOURCE_SELF && $record->check_in_at) {
                    continue;
                }
                $shift = $schedule[$employee->id]['shift'] ?? null;
                $record ??= new AttendanceRecord(['employee_id' => $employee->id, 'work_date' => $d->toDateString()]);
                $worked = in_array($status, AttendanceRecord::WORKED, true) ? $this->shiftMinutes($shift) : 0;
                $record->fill([
                    'company_id' => $employee->company_id,
                    'shift_id' => $shift?->id,
                    'status' => $status,
                    'source' => AttendanceRecord::SOURCE_CREW,
                    'recorded_by' => $user->id,
                    'worked_minutes' => $worked,
                    'overtime_minutes' => 0,
                    'overtime_status' => AttendanceRecord::OVERTIME_NONE,
                ])->save();
                $this->syncManHour($record, $user, $schedule[$employee->id]['project_id'] ?? null);
                $written++;
            }
        });

        return $written;
    }

    // ---------------------------------------------------------------- HRD correction

    /**
     * Create or correct one day's record. Reason is mandatory and logged.
     * $data: status, check_in (H:i, local), check_out (H:i, local; may be next day for night shifts).
     */
    public function correct(User $user, Employee $employee, CarbonInterface $date, array $data, string $reason): AttendanceRecord
    {
        if (! $user->canManageAttendance()) {
            abort(403);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Tulis alasan koreksi minimal 10 karakter, misalnya "lupa absen pulang, dikonfirmasi foreman".']);
        }
        $status = $data['status'] ?? null;
        if (! in_array($status, AttendanceRecord::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Pilih status kehadiran.']);
        }

        $d = $date->toDateString();
        $record = AttendanceRecord::where('employee_id', $employee->id)->whereDate('work_date', $d)->first()
            ?? new AttendanceRecord(['employee_id' => $employee->id, 'work_date' => $d, 'source' => AttendanceRecord::SOURCE_HR]);
        $before = $record->exists ? $record->only(['status', 'check_in_at', 'check_out_at', 'worked_minutes']) : null;

        $shift = $record->shift ?? ($this->scheduleFor($employee, Carbon::parse($d))['shift'] ?? null);
        $in = ! empty($data['check_in']) ? Carbon::parse($d.' '.$data['check_in'], $this->tz()) : null;
        $out = ! empty($data['check_out']) ? Carbon::parse($d.' '.$data['check_out'], $this->tz()) : null;
        if ($in && $out && $out->lte($in)) {
            $out->addDay(); // night shift: check-out the next morning
        }

        $record->fill([
            'company_id' => $employee->company_id,
            'shift_id' => $shift?->id,
            'status' => $status,
            'check_in_at' => $in,
            'check_out_at' => $out,
            'correction_reason' => $reason,
            'corrected_by' => $user->id,
            'corrected_at' => now(),
            'recorded_by' => $record->recorded_by ?? $user->id,
        ]);
        $record->source ??= AttendanceRecord::SOURCE_HR;

        if (! in_array($status, AttendanceRecord::WORKED, true)) {
            $record->fill(['worked_minutes' => 0, 'overtime_minutes' => 0, 'overtime_status' => AttendanceRecord::OVERTIME_NONE]);
        } elseif ($in && $out) {
            $this->computeFromTimes($record);
        } else {
            $record->fill(['worked_minutes' => $this->shiftMinutes($shift), 'overtime_minutes' => 0, 'overtime_status' => AttendanceRecord::OVERTIME_NONE]);
        }
        $record->save();
        $this->syncManHour($record, $user);

        ActivityLog::record('attendance.corrected', sprintf(
            'Koreksi absensi %s tanggal %s: %s. Alasan: %s', $employee->full_name, $d, $status, $reason
        ), $record, ['before' => $before, 'after' => $record->only(['status', 'check_in_at', 'check_out_at', 'worked_minutes'])]);

        return $record->fresh();
    }

    // ---------------------------------------------------------------- overtime

    public function decideOvertime(User $user, AttendanceRecord $record, bool $approve): AttendanceRecord
    {
        if (! $user->canApproveOvertime()) {
            abort(403);
        }
        if ($record->overtime_status !== AttendanceRecord::OVERTIME_PENDING) {
            throw ValidationException::withMessages(['overtime' => 'Lembur ini sudah diputuskan.']);
        }
        $record->fill([
            'overtime_status' => $approve ? AttendanceRecord::OVERTIME_APPROVED : AttendanceRecord::OVERTIME_REJECTED,
            'overtime_decided_by' => $user->id,
            'overtime_decided_at' => now(),
        ])->save();
        $this->syncManHour($record, $user);

        ActivityLog::record($approve ? 'attendance.overtime_approved' : 'attendance.overtime_rejected', sprintf(
            'Lembur %s menit %s tanggal %s %s',
            $record->overtime_minutes, $record->employee?->full_name, $record->work_date->toDateString(), $approve ? 'disetujui' : 'ditolak'
        ), $record);

        return $record->fresh();
    }

    // ---------------------------------------------------------------- daily register & summary

    /**
     * One row per person who is scheduled OR has a record on the date.
     * `state` is the record's status, or `leave` (approved leave, no
     * record), `not_in` (shift already started, nothing recorded) or
     * `upcoming` (shift not started yet).
     */
    public function register(array $companyIds, CarbonInterface $date): Collection
    {
        $d = Carbon::parse($date->toDateString());
        $schedule = $this->scheduled($companyIds, $d);
        $records = AttendanceRecord::query()->with(['employee', 'shift', 'site'])
            ->whereIn('company_id', $companyIds)->whereDate('work_date', $d->toDateString())->get()->keyBy('employee_id');
        $ids = $schedule->keys()->merge($records->keys())->unique()->values()->all();
        $leave = array_flip($this->onLeave($ids, $d));
        $now = Carbon::now($this->tz());
        $tz = $this->tz();

        return collect($ids)->map(function ($id) use ($schedule, $records, $leave, $d, $now, $tz) {
            $s = $schedule[$id] ?? null;
            $r = $records[$id] ?? null;
            $employee = $r?->employee ?? $s['employee'];
            $shift = $r?->shift ?? ($s['shift'] ?? null);
            if ($r) {
                $state = $r->status;
            } elseif (isset($leave[$id])) {
                $state = AttendanceRecord::STATUS_LEAVE;
            } else {
                $start = $shift?->start_time ? Carbon::parse($d->toDateString().' '.$shift->start_time, $tz) : $d->copy()->setTimezone($tz)->setTime(8, 0);
                $state = $now->gt($start->copy()->addMinutes(self::LATE_GRACE_MINUTES)) ? 'not_in' : 'upcoming';
            }

            return [
                'employee_id' => (int) $id,
                'name' => $employee?->full_name,
                'employee_number' => $employee?->employee_id,
                'site_name' => $s['site_name'] ?? $r?->site?->name,
                'scheduled' => $s !== null,
                'shift' => $shift ? ['id' => $shift->id, 'name' => $shift->name, 'start' => substr((string) $shift->start_time, 0, 5), 'end' => substr((string) $shift->end_time, 0, 5)] : null,
                'state' => $state,
                'record_id' => $r?->id,
                'source' => $r?->source,
                'check_in' => $r?->check_in_at?->copy()->setTimezone($tz)->format('H:i'),
                'check_out' => $r?->check_out_at?->copy()->setTimezone($tz)->format('H:i'),
                'worked_minutes' => $r?->worked_minutes,
                'overtime_minutes' => $r?->overtime_minutes ?? 0,
                'overtime_status' => $r?->overtime_status ?? AttendanceRecord::OVERTIME_NONE,
                'distance_m' => $r?->distance_m,
                'corrected' => (bool) $r?->corrected_at,
                'correction_reason' => $r?->correction_reason,
            ];
        })->sortBy(fn ($row) => [$row['site_name'] ?? 'zzz', $row['name']])->values();
    }

    public function summary(array $companyIds, CarbonInterface $date): array
    {
        $d = Carbon::parse($date->toDateString());
        $rows = $this->register($companyIds, $d);
        $scheduled = $rows->where('scheduled', true);
        $count = fn (string $state) => $rows->where('state', $state)->count();
        $worked = $rows->whereIn('state', AttendanceRecord::WORKED)->count();

        $weekStart = $d->copy()->startOfWeek();
        $overtime = AttendanceRecord::query()->whereIn('company_id', $companyIds)
            ->whereDate('work_date', '>=', $weekStart->toDateString())->whereDate('work_date', '<=', $d->toDateString())
            ->whereIn('overtime_status', [AttendanceRecord::OVERTIME_PENDING, AttendanceRecord::OVERTIME_APPROVED])
            ->selectRaw('overtime_status, SUM(overtime_minutes) as m')->groupBy('overtime_status')->pluck('m', 'overtime_status');

        $trend = collect(range(13, 0))->map(function ($i) use ($companyIds, $d) {
            $day = $d->copy()->subDays($i);
            $sched = $this->scheduled($companyIds, $day)->count();
            $present = AttendanceRecord::query()->whereIn('company_id', $companyIds)->whereDate('work_date', $day->toDateString())
                ->whereIn('status', AttendanceRecord::WORKED)->count();

            return ['date' => $day->toDateString(), 'scheduled' => $sched, 'present' => $present,
                'rate' => $sched > 0 ? round(min(1, $present / $sched), 3) : null];
        })->values();

        $bySite = $rows->groupBy(fn ($r) => $r['site_name'] ?: 'Tanpa site')->map(fn ($g, $site) => [
            'site' => $site,
            'scheduled' => $g->where('scheduled', true)->count(),
            'present' => $g->whereIn('state', AttendanceRecord::WORKED)->count(),
            'late' => $g->where('state', AttendanceRecord::STATUS_LATE)->count(),
            'not_in' => $g->where('state', 'not_in')->count(),
            'hours' => round($g->sum('worked_minutes') / 60, 1),
        ])->sortBy('site')->values();

        // Absent (alfa) on each of the last three days.
        $days = collect(range(0, 2))->map(fn ($i) => $d->copy()->subDays($i)->toDateString())->all();
        $streak = AttendanceRecord::query()->whereIn('company_id', $companyIds)
            ->whereDate('work_date', '>=', end($days))->whereDate('work_date', '<=', $days[0])
            ->where('status', AttendanceRecord::STATUS_ABSENT)
            ->selectRaw('employee_id, COUNT(*) as n')->groupBy('employee_id')->havingRaw('COUNT(*) >= 3')->pluck('employee_id')->count();
        $openYesterday = AttendanceRecord::query()->whereIn('company_id', $companyIds)
            ->whereDate('work_date', $d->copy()->subDay()->toDateString())
            ->whereNotNull('check_in_at')->whereNull('check_out_at')->count();

        return [
            'date' => $d->toDateString(),
            'scheduled' => $scheduled->count(),
            'present' => $worked,
            'late' => $count(AttendanceRecord::STATUS_LATE),
            'not_in' => $count('not_in'),
            'leave' => $count(AttendanceRecord::STATUS_LEAVE),
            'sick' => $count(AttendanceRecord::STATUS_SICK),
            'permit' => $count(AttendanceRecord::STATUS_PERMIT),
            'absent' => $count(AttendanceRecord::STATUS_ABSENT),
            'rate' => $scheduled->count() > 0 ? round(min(1, $worked / $scheduled->count()), 3) : null,
            'overtime_week_minutes' => (int) (($overtime[AttendanceRecord::OVERTIME_APPROVED] ?? 0) + ($overtime[AttendanceRecord::OVERTIME_PENDING] ?? 0)),
            'overtime_pending_minutes' => (int) ($overtime[AttendanceRecord::OVERTIME_PENDING] ?? 0),
            'trend' => $trend,
            'by_site' => $bySite,
            'absent_streak' => $streak,
            'open_yesterday' => $openYesterday,
        ];
    }

    /** The signed-in worker's attendance card on My Work. */
    public function myStatus(User $user): ?array
    {
        $employee = $user->employee;
        if (! $employee) {
            return null;
        }
        $today = $this->today();
        $tz = $this->tz();
        $record = AttendanceRecord::where('employee_id', $employee->id)
            ->where(fn ($q) => $q->whereDate('work_date', $today->toDateString())->orWhereDate('work_date', $today->copy()->subDay()->toDateString()))
            ->orderByDesc('work_date')->get()
            ->first(fn ($r) => $r->work_date->toDateString() === $today->toDateString() || ($r->check_in_at && ! $r->check_out_at));
        $schedule = $this->scheduleFor($employee, $today);
        $shift = $record?->shift ?? ($schedule['shift'] ?? null);

        return [
            'shift' => $shift ? ['name' => $shift->name, 'start' => substr((string) $shift->start_time, 0, 5), 'end' => substr((string) $shift->end_time, 0, 5)] : null,
            'site_name' => $schedule['site_name'] ?? null,
            'status' => $record?->status,
            'check_in' => $record?->check_in_at?->copy()->setTimezone($tz)->format('H:i'),
            'check_out' => $record?->check_out_at?->copy()->setTimezone($tz)->format('H:i'),
            'worked_minutes' => $record?->worked_minutes,
            'can_check_in' => ! $record?->check_in_at && $record?->status !== AttendanceRecord::STATUS_LEAVE,
            'can_check_out' => $record && $record->check_in_at && ! $record->check_out_at,
            'requires_location' => $this->requiresLocation()
                && AttendanceSite::query()->where('company_id', $employee->company_id)->where('is_active', true)->exists(),
        ];
    }

    // ---------------------------------------------------------------- derived numbers

    public function shiftMinutes(?Shift $shift): int
    {
        $h = $shift?->working_hours;

        return $h ? (int) round($h * 60) : 8 * 60;
    }

    /** worked = time between check-in and check-out minus the shift break; overtime above the shift. */
    public function computeFromTimes(AttendanceRecord $record): void
    {
        if (! $record->check_in_at || ! $record->check_out_at) {
            return;
        }
        $span = (int) abs($record->check_in_at->diffInMinutes($record->check_out_at));
        $shift = $record->shift_id ? Shift::find($record->shift_id) : null;
        $break = $shift ? (int) $shift->break_duration_minutes : 0;
        $worked = max(0, $span - ($span > $break * 2 ? $break : 0));
        $over = $shift ? max(0, $worked - $this->shiftMinutes($shift)) : 0;
        if ($over < self::OVERTIME_MIN_MINUTES) {
            $over = 0;
        }
        $record->worked_minutes = $worked;
        $record->overtime_minutes = $over;
        if ($over === 0) {
            $record->overtime_status = AttendanceRecord::OVERTIME_NONE;
        } elseif (! in_array($record->overtime_status, [AttendanceRecord::OVERTIME_APPROVED, AttendanceRecord::OVERTIME_REJECTED], true)) {
            $record->overtime_status = AttendanceRecord::OVERTIME_PENDING;
        }
    }

    /**
     * Keep Man-Hour in step with attendance. Regular hours = worked minus
     * overtime; overtime hours only when approved. A Man-Hour row this
     * record did not create is left untouched.
     */
    public function syncManHour(AttendanceRecord $record, ?User $user = null, ?int $projectId = null): void
    {
        $d = $record->work_date->toDateString();
        $existing = ManHourLog::where('employee_id', $record->employee_id)->whereDate('work_date', $d)->first();
        $ours = $existing && ($existing->id === $record->man_hour_log_id || $existing->notes === self::MAN_HOUR_NOTE);

        if ($existing && ! $ours) {
            return; // manual entry wins; attendance never overwrites it
        }

        $worked = in_array($record->status, AttendanceRecord::WORKED, true) ? (int) $record->worked_minutes : 0;
        if ($worked <= 0) {
            if ($existing) {
                $existing->delete();
            }
            if ($record->man_hour_log_id) {
                $record->forceFill(['man_hour_log_id' => null])->saveQuietly();
            }

            return;
        }

        $over = (int) $record->overtime_minutes;
        $regular = min(999.99, round(($worked - $over) / 60, 2));
        $overtime = $record->overtime_status === AttendanceRecord::OVERTIME_APPROVED ? min(999.99, round($over / 60, 2)) : 0;

        $log = $existing ?? new ManHourLog(['employee_id' => $record->employee_id, 'work_date' => $d]);
        $log->fill([
            'company_id' => $record->company_id,
            'regular_hours' => $regular,
            'overtime_hours' => $overtime,
            'notes' => self::MAN_HOUR_NOTE,
            'recorded_by' => $log->recorded_by ?? $user?->id,
        ]);
        if ($projectId !== null || ! $log->exists) {
            $log->project_id = $projectId ?? $this->scheduleFor($record->employee, $record->work_date)['project_id'] ?? null;
        }
        $log->save();

        if ($record->man_hour_log_id !== $log->id) {
            $record->forceFill(['man_hour_log_id' => $log->id])->saveQuietly();
        }
    }

    private function employeeOf(User $user): Employee
    {
        $employee = $user->employee;
        if (! $employee) {
            throw ValidationException::withMessages(['attendance' => 'Akun ini belum terhubung ke data karyawan. Minta HRD menghubungkannya.']);
        }

        return $employee;
    }
}
