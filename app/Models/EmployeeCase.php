<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * v2.69.0 -- Employee Case. HR's record of a concern raised about a
 * person: what was raised, who is handling it, what was decided, and what
 * (if anything) was issued as a result.
 *
 * WHY A CASE RATHER THAN A FLAG. The requirement this answers asked for a
 * historical record rather than a status on the employee, and those are
 * genuinely different things. A column can say "this person has a
 * warning"; it cannot say which warning, for what, issued by whom, still
 * in force until when, or how many times before. It also goes stale
 * silently -- a warning lapses on a date, and a column does not notice.
 *
 * So nothing about standing is stored. `Employee::currentDisciplinaryStanding()`
 * derives it from the actions below, the same way `Employee::profile_status`,
 * `PurchaseOrderItem::delivered_quantity` and Vendor Performance are all
 * derived rather than kept -- this codebase's consistent answer to "a
 * number that could drift".
 *
 * LIFECYCLE. Five states, each one a real operational moment:
 *
 *   open          raised, nobody has picked it up
 *   under_review  HR is looking into it
 *   action_issued something was issued; its validity window is running
 *   closed        concluded
 *   dismissed     reviewed, nothing to answer
 *
 * `dismissed` is deliberately distinct from `closed`: a case that was
 * investigated and found to have no substance must not end in the same
 * state as one that ran its course, because the difference is the whole
 * point from the employee's side. Both are reopenable by an overrider,
 * following the same precedent as MaterialRequest's admin-only reopen.
 */
class EmployeeCase extends Model
{
    use BelongsToCompany, HasWorkflow, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_ACTION_ISSUED = 'action_issued';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_DISMISSED = 'dismissed';

    public const CATEGORIES = ['conduct', 'attendance', 'performance', 'safety', 'policy', 'other'];

    public const SEVERITIES = ['low', 'medium', 'high'];

    public const OUTCOMES = ['substantiated', 'unsubstantiated', 'withdrawn'];

    /** Still needs somebody to do something. */
    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_ACTION_ISSUED,
    ];

    /**
     * `action_issued` is reachable only from `under_review`: issuing a
     * sanction on a case nobody has reviewed is precisely the shortcut
     * this record exists to prevent. Closing from `under_review` directly
     * is allowed and means "reviewed, concluded, nothing issued" --
     * distinct from `dismissed`, which means "no case to answer".
     */
    protected static array $transitions = [
        self::STATUS_OPEN => [self::STATUS_UNDER_REVIEW, self::STATUS_DISMISSED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_ACTION_ISSUED, self::STATUS_CLOSED, self::STATUS_DISMISSED],
        self::STATUS_ACTION_ISSUED => [self::STATUS_CLOSED],
        // Reopening is an override, gated in the controller -- the same
        // shape as MaterialRequest's rejected -> draft path.
        self::STATUS_CLOSED => [self::STATUS_UNDER_REVIEW],
        self::STATUS_DISMISSED => [self::STATUS_UNDER_REVIEW],
    ];

    protected $fillable = [
        'case_number',
        'company_id',
        'employee_id',
        'category',
        'severity',
        'title',
        'details',
        'reported_by',
        'reported_at',
        'assigned_to',
        'status',
        'outcome',
        'closed_by',
        'closed_at',
        'closure_note',
    ];

    protected function casts(): array
    {
        return [
            'reported_at' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    protected $appends = ['is_active'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function actions()
    {
        return $this->hasMany(EmployeeCaseAction::class)->orderByDesc('issued_at');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function getIsActiveAttribute(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * HasWorkflow notifies `requester`/`reporter`/`creator` by default.
     * For a case that would tell whoever raised the concern every time HR
     * moves it along, which is both noisy and a confidentiality problem:
     * the person who reported it is not necessarily entitled to follow
     * the outcome. The handler is the right recipient -- they are the one
     * answerable for it.
     */
    protected function notificationRecipient(): ?User
    {
        return $this->assignee;
    }

    public static function generateCaseNumber(?int $companyId = null): string
    {
        return app(\App\Services\NumberGeneratorService::class)->generate('employee_case', $companyId);
    }
}
