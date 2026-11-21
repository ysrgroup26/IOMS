<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * v2.146.0 -- one employee's attendance on one work date.
 *
 * Taken one of three ways (`source`): the worker's own check-in on My Work
 * (`self`), a foreman's crew roll call (`crew`), or an HRD correction
 * (`hr`). Worked minutes feed Man-Hour; overtime does so only once a
 * supervisor approves it. See AttendanceService for every rule.
 */
class AttendanceRecord extends Model
{
    use BelongsToCompany;

    public const STATUS_PRESENT = 'present';

    public const STATUS_LATE = 'late';

    public const STATUS_SICK = 'sick';

    public const STATUS_PERMIT = 'permit';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_LEAVE = 'leave';

    public const STATUSES = [
        self::STATUS_PRESENT, self::STATUS_LATE, self::STATUS_SICK,
        self::STATUS_PERMIT, self::STATUS_ABSENT, self::STATUS_LEAVE,
    ];

    /** Statuses that mean the person worked that day. */
    public const WORKED = [self::STATUS_PRESENT, self::STATUS_LATE];

    public const SOURCE_SELF = 'self';

    public const SOURCE_CREW = 'crew';

    public const SOURCE_HR = 'hr';

    public const OVERTIME_NONE = 'none';

    public const OVERTIME_PENDING = 'pending';

    public const OVERTIME_APPROVED = 'approved';

    public const OVERTIME_REJECTED = 'rejected';

    protected $fillable = [
        'company_id', 'employee_id', 'work_date', 'shift_id', 'status',
        'check_in_at', 'check_out_at',
        'check_in_latitude', 'check_in_longitude', 'check_out_latitude', 'check_out_longitude',
        'site_id', 'distance_m', 'source', 'recorded_by',
        'worked_minutes', 'overtime_minutes', 'overtime_status', 'overtime_decided_by', 'overtime_decided_at',
        'man_hour_log_id', 'correction_reason', 'corrected_by', 'corrected_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'check_in_latitude' => 'float',
            'check_in_longitude' => 'float',
            'check_out_latitude' => 'float',
            'check_out_longitude' => 'float',
            'distance_m' => 'integer',
            'worked_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'overtime_decided_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function site()
    {
        return $this->belongsTo(AttendanceSite::class, 'site_id');
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function manHourLog()
    {
        return $this->belongsTo(ManHourLog::class);
    }
}
