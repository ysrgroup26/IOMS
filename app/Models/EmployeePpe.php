<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Doubles as "PPE Distribution" (creating a row = issuing PPE) and
 * "PPE History" (listing rows per employee) -- the same table serves both
 * UI concepts, since they're the same information viewed two ways.
 *
 * v1.5.1 lifecycle redesign: `status` now walks a clear manual business
 * workflow --
 *
 *   issued -> in_use -> replacement_requested -> replacement_approved
 *   -> replacement_completed -> archived
 *
 * "Expired" is deliberately NOT one of these manual states. It's a
 * computed overlay (see getEffectiveStatusAttribute()) that only applies
 * while status is still `issued` or `in_use` and the expiry date has
 * passed -- replacement is never triggered automatically by expiry, it
 * always remains a manual, explicit process the way the spec requires.
 * When a replacement is completed, THIS record is archived and a brand
 * new EmployeePpe row is created for the new item (see
 * PpeController::completeReplacement()) -- a replaced record can never
 * silently become active again.
 *
 * v1.6.7 status review: a request came in to redefine `issued` as
 * "exists in company inventory, not yet assigned to an employee" and
 * `in_use` as "assigned and actively used." That's a genuinely different
 * meaning than what `issued` has meant since v1.5.1 above (a record that
 * already belongs to a specific employee, just not yet confirmed as
 * actively in use) -- and every existing row's `status` was created
 * under the CURRENT meaning. Redefining it in place would silently
 * change the meaning of real historical data with no way to
 * distinguish which meaning any given row was recorded under.
 *
 * What actually blocks "exists in inventory, unassigned" from being
 * representable at all is structural, not a status-naming problem:
 * `employee_id` was a required column, so no row could ever exist
 * without an employee. That's now fixed (see the
 * make_employee_id_nullable_on_employee_ppe migration) -- a genuinely
 * unassigned inventory record can now exist (employee_id null),
 * completely independent of the `status` enum, which keeps its current
 * meaning for the assigned-to-someone lifecycle it already tracks. No
 * current code path creates a null-employee_id row yet -- there's no
 * "add to inventory" UI -- so this is inert until a future session
 * builds that feature on top of it, exactly the "foundation, not the
 * complete feature" scope this review asked for.
 */
class EmployeePpe extends Model
{
    protected $table = 'employee_ppe';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_IN_USE = 'in_use';

    public const STATUS_REPLACEMENT_REQUESTED = 'replacement_requested';

    public const STATUS_REPLACEMENT_APPROVED = 'replacement_approved';

    public const STATUS_REPLACEMENT_COMPLETED = 'replacement_completed';

    public const STATUS_ARCHIVED = 'archived';

    /** Statuses where the item is still genuinely in the employee's possession. */
    public const IN_SERVICE_STATUSES = [self::STATUS_ISSUED, self::STATUS_IN_USE];

    protected $fillable = [
        'employee_id',
        'ppe_type_id',
        'issued_date',
        'expiry_date',
        'status',
        'remarks',
        'issued_by',
    ];

    protected function casts(): array
    {
        return [
            'issued_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Expiry is always derived from the PPE type's replacement interval
        // at issuance time, never entered manually -- one source of truth.
        static::creating(function (EmployeePpe $record) {
            if (! $record->expiry_date && $record->issued_date) {
                $type = PpeType::find($record->ppe_type_id);
                if ($type && $type->replacement_interval_months) {
                    $record->expiry_date = Carbon::parse($record->issued_date)
                        ->addMonths($type->replacement_interval_months);
                }
            }
        });
    }

    protected $appends = ['effective_status', 'days_remaining'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function ppeType()
    {
        return $this->belongsTo(PpeType::class);
    }

    public function issuedBy()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * "In service" -- issued or in_use, i.e. not yet archived and not in
     * the replacement pipeline. This is the closest equivalent to the
     * old, single "active" status.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', self::IN_SERVICE_STATUSES);
    }

    /**
     * SQL-level equivalent of getEffectiveStatusAttribute(), so PPE lists
     * can be filtered by expiry classification (e.g. from the PPE
     * Dashboard's clickable cards) without loading every row into PHP
     * first. Must stay logically identical to the accessor below.
     */
    public function scopeEffectiveStatus($query, string $status)
    {
        $today = now()->toDateString();
        $soonCutoff = now()->addDays(30)->toDateString();

        return match ($status) {
            'expired' => $query->whereIn('status', self::IN_SERVICE_STATUSES)
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '<', $today),
            'expiring_soon' => $query->whereIn('status', self::IN_SERVICE_STATUSES)
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '>=', $today)
                ->where('expiry_date', '<=', $soonCutoff),
            'active' => $query->whereIn('status', self::IN_SERVICE_STATUSES)
                ->where(function ($q) use ($soonCutoff) {
                    $q->whereNull('expiry_date')->orWhere('expiry_date', '>', $soonCutoff);
                }),
            default => $query->where('status', $status), // any lifecycle status, passthrough
        };
    }

    /**
     * Fully-automatic expiry classification -- computed on every access,
     * never stored, so it can never go stale. Only overlays while the
     * item is still issued/in_use; once it enters the replacement
     * pipeline or is archived, the manual lifecycle status is shown as-is
     * regardless of dates.
     */
    public function isExpired(): bool
    {
        return in_array($this->status, self::IN_SERVICE_STATUSES, true)
            && $this->expiry_date
            && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(int $withinDays = 30): bool
    {
        return in_array($this->status, self::IN_SERVICE_STATUSES, true)
            && $this->expiry_date
            && ! $this->expiry_date->isPast()
            && $this->expiry_date->diffInDays(now()) <= $withinDays;
    }

    /**
     * One of: issued, in_use, expired, expiring_soon, replacement_requested,
     * replacement_approved, replacement_completed, archived. This -- not
     * the raw `status` column alone -- is what every PPE list/badge in
     * the app should display and filter by.
     */
    public function getEffectiveStatusAttribute(): string
    {
        if (! in_array($this->status, self::IN_SERVICE_STATUSES, true)) {
            return $this->status; // mid-workflow or terminal -- manual, unaffected by dates
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->isExpiringSoon()) {
            return 'expiring_soon';
        }

        return $this->status; // 'issued' or 'in_use', not yet due
    }

    /**
     * Positive = days remaining until expiry, negative = days overdue,
     * null = no expiry date (request-based equipment like Harness).
     */
    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_date->copy()->startOfDay(), false);
    }
}
