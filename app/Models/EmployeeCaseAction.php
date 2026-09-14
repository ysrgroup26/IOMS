<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyThrough;
use Illuminate\Database\Eloquent\Model;

/**
 * v2.69.0 -- a disciplinary action issued within an Employee Case.
 *
 * One case can produce several over time (counselling, then a written
 * warning when the same thing recurs), and an employee's standing is the
 * sum of what is still in force across all of their cases.
 *
 * THE VALIDITY WINDOW IS THE POINT. A Surat Peringatan is valid for a
 * fixed term -- six months is the usual one -- and once it lapses the
 * employee is back to a clean standing, which is what decides whether the
 * next incident escalates. Storing the window and deriving standing from
 * it means the record ages correctly with no scheduled job and nothing to
 * keep in sync; storing a "current level" instead would be right on the
 * day it was written and wrong every day after.
 *
 * TYPES keep the local terms IOMS already keeps elsewhere (PTW, HIRADC,
 * JSA, LOTO): `sp1`/`sp2`/`sp3` are Surat Peringatan I/II/III, the actual
 * document an Indonesian employer issues. Renaming them to "Warning
 * Letter Level 1" would stop them matching the paper they describe.
 */
class EmployeeCaseAction extends Model
{
    use BelongsToCompanyThrough;

    public const TYPE_COUNSELLING = 'counselling';

    public const TYPE_VERBAL_WARNING = 'verbal_warning';

    public const TYPE_WRITTEN_WARNING = 'written_warning';

    public const TYPE_SP1 = 'sp1';

    public const TYPE_SP2 = 'sp2';

    public const TYPE_SP3 = 'sp3';

    public const TYPE_SUSPENSION = 'suspension';

    public const TYPE_DEMOTION = 'demotion';

    public const TYPE_TERMINATION = 'termination';

    /**
     * Ordered least to most serious. The ORDER is the meaningful part --
     * it is what "the most severe action currently in force" means, and
     * what an escalation decision is read against. The numbers themselves
     * are ranks, never scores, and nothing sums them.
     */
    public const SEVERITY_RANK = [
        self::TYPE_COUNSELLING => 1,
        self::TYPE_VERBAL_WARNING => 2,
        self::TYPE_WRITTEN_WARNING => 3,
        self::TYPE_SP1 => 4,
        self::TYPE_SP2 => 5,
        self::TYPE_SP3 => 6,
        self::TYPE_SUSPENSION => 7,
        self::TYPE_DEMOTION => 8,
        self::TYPE_TERMINATION => 9,
    ];

    public const TYPES = [
        self::TYPE_COUNSELLING,
        self::TYPE_VERBAL_WARNING,
        self::TYPE_WRITTEN_WARNING,
        self::TYPE_SP1,
        self::TYPE_SP2,
        self::TYPE_SP3,
        self::TYPE_SUSPENSION,
        self::TYPE_DEMOTION,
        self::TYPE_TERMINATION,
    ];

    /** Owned through its case -- see BelongsToCompanyThrough. */
    protected string $companyOwnerRelation = 'employeeCase';

    protected $fillable = [
        'employee_case_id',
        'type',
        'issued_at',
        'effective_until',
        'reference_number',
        'notes',
        'issued_by',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'effective_until' => 'date',
            'acknowledged_at' => 'datetime',
        ];
    }

    protected $appends = ['is_in_force', 'severity_rank'];

    public function employeeCase()
    {
        return $this->belongsTo(EmployeeCase::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Issued, and not yet lapsed. A null `effective_until` means the
     * action does not lapse (termination), which is different from
     * "unknown" -- so it stays in force rather than being treated as
     * expired.
     */
    public function getIsInForceAttribute(): bool
    {
        if (! $this->issued_at || $this->issued_at->startOfDay()->isFuture()) {
            return false;
        }

        return $this->effective_until === null
            || $this->effective_until->endOfDay()->isFuture();
    }

    public function getSeverityRankAttribute(): int
    {
        return self::SEVERITY_RANK[$this->type] ?? 0;
    }

    /** Issued and not lapsed, expressed as a query rather than in PHP. */
    public function scopeInForce($query)
    {
        return $query->whereDate('issued_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', now());
            });
    }
}
