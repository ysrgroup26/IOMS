<?php

namespace App\Models;

use App\Concerns\HasApprovals;
use App\Concerns\HasWorkflow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Leave (v1.10.0) -- HR's first real, non-placeholder sidebar item beyond
 * Employees. Second real consumer of the Universal Approval Engine
 * (HasApprovals) and Workflow Engine (HasWorkflow), after Material
 * Request -- proves both are genuinely reusable, not Material-Request-
 * specific. Deliberately no line items and no "processing" step: an
 * approved leave doesn't need a warehouse-style hand-off, it's simply
 * granted.
 */
class LeaveRequest extends Model
{
    use HasApprovals, HasWorkflow, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPES = ['annual', 'sick', 'unpaid', 'other'];

    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_DRAFT],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'leave_number',
        'employee_id',
        'company_id',
        'leave_type',
        'start_date',
        'end_date',
        'days',
        'reason',
        'status',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** LR-{YEAR}-{00001}, same per-year sequential convention as Material Request (MR-) and Tasks (TSK-). */
    public static function generateLeaveNumber(): string
    {
        $year = now()->year;
        $lastNumber = static::withTrashed()
            ->where('leave_number', 'like', "LR-{$year}-%")
            ->orderByDesc('id')
            ->value('leave_number');

        $sequence = $lastNumber ? ((int) substr($lastNumber, -5)) + 1 : 1;

        return sprintf('LR-%d-%05d', $year, $sequence);
    }
}
