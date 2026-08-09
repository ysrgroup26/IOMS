<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Milestone 4, Workstream A3 (Shift & Roster Management). Shift Master.
 * See the owning migration's own doc comment
 * (2026_08_09_113301_create_shifts_table) for why start_time/end_time
 * are plain TIME strings rather than a datetime cast.
 */
class Shift extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'start_time',
        'end_time',
        'break_duration_minutes',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'break_duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected $appends = ['is_night_shift', 'working_hours'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function shiftAssignments()
    {
        return $this->hasMany(EmployeeShiftAssignment::class);
    }

    public function rosters()
    {
        return $this->hasMany(EmployeeRoster::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Fatigue Management Foundation -- "night shift indicator". A shift
     * crosses midnight (and is therefore a night shift) whenever its end
     * time is earlier in the clock than its start time -- e.g.
     * 23:00-07:00. Computed on every access, never stored, so it can
     * never drift from the actual start/end times if either is edited.
     */
    public function getIsNightShiftAttribute(): bool
    {
        if (! $this->start_time || ! $this->end_time) {
            return false;
        }

        return Carbon::parse($this->end_time)->lt(Carbon::parse($this->start_time));
    }

    /**
     * Fatigue Management Foundation -- "working hours tracking". Total
     * scheduled hours for one occurrence of this shift (span minus
     * break), correctly handling the overnight-wraparound case flagged
     * by is_night_shift above.
     */
    public function getWorkingHoursAttribute(): float
    {
        if (! $this->start_time || ! $this->end_time) {
            return 0.0;
        }

        $start = Carbon::parse($this->start_time);
        $end = Carbon::parse($this->end_time);

        if ($end->lt($start)) {
            $end->addDay(); // overnight shift -- end time belongs to the next calendar day
        }

        // Carbon 3 (Laravel 12) changed diffInMinutes() to return a SIGNED
        // value by default (no longer absolute like Carbon 2) -- found and
        // fixed live during this feature's own verification: a real
        // 23:00-07:00 shift was computing "0 h" working hours instead of
        // 7h because the signed result went negative before max(...,0)
        // clamped it away. abs() makes this correct regardless of which
        // Carbon major version is in play.
        $minutes = abs($end->diffInMinutes($start)) - $this->break_duration_minutes;

        return round(max($minutes, 0) / 60, 2);
    }

    /**
     * Fatigue Management Foundation -- "rest period monitoring"
     * foundation. The gap, in hours, between this shift's end and
     * another shift's start on the immediately following calendar day --
     * the real, computable building block a future consecutive-shift
     * fatigue check would use (e.g. flagging < 8h rest between a night
     * shift ending and the next shift starting). Deliberately a pure
     * two-shift calculation, not a full multi-day monitoring engine --
     * that needs real roster/attendance history to walk, which is what
     * EmployeeRoster::dutyTypeOn() exists to provide when that engine is
     * actually built.
     */
    public function restHoursBefore(Shift $nextShift): float
    {
        $start = Carbon::parse($this->start_time);
        $thisEnd = Carbon::parse($this->end_time);
        if ($thisEnd->lt($start)) {
            $thisEnd->addDay();
        }

        $nextStart = Carbon::parse($nextShift->start_time)->addDay();

        // See getWorkingHoursAttribute()'s own comment on why abs() is
        // required here under Carbon 3's signed diffInMinutes() default.
        return round(abs($nextStart->diffInMinutes($thisEnd)) / 60, 2);
    }
}
