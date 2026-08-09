<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Milestone 4, Workstream A3. Roster Management -- see the owning
 * migration's own doc comment
 * (2026_08_09_113304_create_employee_rosters_table).
 */
class EmployeeRoster extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'employee_id',
        'shift_id',
        'roster_pattern_id',
        'project_id',
        'site_name',
        'start_date',
        'end_date',
        'cycle_start_date',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'cycle_start_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // cycle_start_date defaults to start_date -- the pattern's cycle
        // begins the same day the roster itself begins unless explicitly
        // anchored elsewhere (e.g. an employee joining an already-running
        // rotation mid-cycle).
        static::creating(function (EmployeeRoster $roster) {
            if (! $roster->cycle_start_date && $roster->start_date) {
                $roster->cycle_start_date = $roster->start_date;
            }
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function rosterPattern()
    {
        return $this->belongsTo(RosterPattern::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCurrent($query)
    {
        $today = now()->toDateString();

        return $query->where('status', self::STATUS_ACTIVE)
            ->where('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            });
    }

    /**
     * 'on' or 'off' duty for a given date -- delegates the actual
     * rotation math to RosterPattern::dutyTypeOn() when a pattern is
     * attached; a roster with no pattern is on duty every day within its
     * own date range (the common fixed-shift/office case, see the
     * owning migration's own doc comment).
     */
    public function dutyTypeOn(Carbon $date): string
    {
        if (! $this->rosterPattern) {
            return 'on';
        }

        return $this->rosterPattern->dutyTypeOn($this->cycle_start_date ?? $this->start_date, $date);
    }
}
