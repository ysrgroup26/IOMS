<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Milestone 4, Workstream A3. Configurable rotation pattern -- see the
 * owning migration's own doc comment
 * (2026_08_09_113303_create_roster_patterns_table).
 */
class RosterPattern extends Model
{
    protected $fillable = [
        'company_id',
        'name',
        'code',
        'days_on',
        'days_off',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'days_on' => 'integer',
            'days_off' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function rosters()
    {
        return $this->hasMany(EmployeeRoster::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    /**
     * Given an anchor date (the roster entry's own cycle_start_date) and
     * any target date, returns 'on' or 'off' -- the actual rotation math
     * (e.g. 6-on/1-off: days 0-5 of the cycle are on, day 6 is off),
     * cycling indefinitely. Used by EmployeeRoster::dutyTypeOn(), kept
     * on the pattern itself since the cycle length is the pattern's own
     * property, not the roster entry's.
     */
    public function dutyTypeOn(Carbon $cycleStart, Carbon $targetDate): string
    {
        $cycleLength = $this->days_on + $this->days_off;

        if ($cycleLength <= 0) {
            return 'on';
        }

        // abs(): a target date before the cycle's own anchor is an edge
        // case (normally cycle_start_date defaults to the roster's own
        // start_date, so targetDate >= cycleStart in practice), but PHP's
        // % operator on a negative dividend returns a negative result --
        // guarding with abs() here keeps this classification well-defined
        // either way rather than silently misclassifying on/off.
        $daysSinceStart = abs($cycleStart->copy()->startOfDay()->diffInDays($targetDate->copy()->startOfDay()));
        $dayInCycle = $daysSinceStart % $cycleLength;

        return $dayInCycle < $this->days_on ? 'on' : 'off';
    }
}
