<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Milestone 4, Workstream A2. One employee's record of having achieved
 * one CompetencyType (a training completion or a certification). Status
 * classification (`effective_status`, `days_remaining`, `scopeEffectiveStatus`)
 * deliberately mirrors EmployeePpe's own expiry-tracking pattern exactly
 * (same 30-day "expiring soon" window, same computed-not-stored
 * philosophy) -- this codebase already has one correct way to answer
 * "is this dated record still valid", no reason to invent a second one.
 */
class EmployeeCompetency extends Model
{
    protected $fillable = [
        'employee_id',
        'competency_type_id',
        'certificate_number',
        'issuer',
        'achieved_date',
        'expiry_date',
        'attachment_path',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'achieved_date' => 'date',
            'expiry_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Same precedent as EmployeePpe::booted() -- expiry is derived
        // from the competency type's validity period at the moment the
        // record is achieved, unless a specific expiry was entered by
        // hand (e.g. a certificate whose actual printed expiry differs
        // slightly from the type's default validity period).
        static::creating(function (EmployeeCompetency $record) {
            if (! $record->expiry_date && $record->achieved_date) {
                $type = CompetencyType::find($record->competency_type_id);
                if ($type && $type->validity_months) {
                    $record->expiry_date = Carbon::parse($record->achieved_date)
                        ->addMonths($type->validity_months);
                }
            }
        });
    }

    protected $appends = ['effective_status', 'days_remaining'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function competencyType()
    {
        return $this->belongsTo(CompetencyType::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * SQL-level equivalent of getEffectiveStatusAttribute() -- see
     * EmployeePpe::scopeEffectiveStatus()'s own comment on why this must
     * stay logically identical to the accessor below.
     */
    public function scopeEffectiveStatus($query, string $status)
    {
        $today = now()->toDateString();
        $soonCutoff = now()->addDays(30)->toDateString();

        return match ($status) {
            'expired' => $query->whereNotNull('expiry_date')->where('expiry_date', '<', $today),
            'expiring_soon' => $query->whereNotNull('expiry_date')
                ->where('expiry_date', '>=', $today)
                ->where('expiry_date', '<=', $soonCutoff),
            'valid' => $query->where(function ($q) use ($soonCutoff) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>', $soonCutoff);
            }),
            default => $query,
        };
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(int $withinDays = 30): bool
    {
        return $this->expiry_date
            && ! $this->expiry_date->isPast()
            && $this->expiry_date->diffInDays(now()) <= $withinDays;
    }

    /**
     * One of: no_expiry, valid, expiring_soon, expired.
     */
    public function getEffectiveStatusAttribute(): string
    {
        if (! $this->expiry_date) {
            return 'no_expiry';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        if ($this->isExpiringSoon()) {
            return 'expiring_soon';
        }

        return 'valid';
    }

    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_date->copy()->startOfDay(), false);
    }
}
