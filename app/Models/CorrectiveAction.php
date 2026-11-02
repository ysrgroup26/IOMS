<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Notifications\AssignmentNotifier;
use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 4, Workstream B1/B15. Reusable CAPA building block -- see the
 * owning migration's own doc comment
 * (2026_08_19_100064_create_corrective_actions_table) for why this is
 * polymorphic and not Safety-Observation-only.
 */
class CorrectiveAction extends Model
{
    use BelongsToCompany;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $fillable = [
        'company_id',
        'source_type',
        'source_id',
        'action',
        'assigned_to',
        'priority',
        'due_date',
        'status',
        'evidence_path',
        'verified_by',
        'verified_at',
        'closed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // v2.141.0: the assignee is told, whichever module raised the action.
        static::created(function (CorrectiveAction $action) {
            if ($action->assigned_to) {
                app(AssignmentNotifier::class)->correctiveAction($action);
            }
        });
        static::updated(function (CorrectiveAction $action) {
            if ($action->wasChanged('assigned_to') && $action->assigned_to) {
                app(AssignmentNotifier::class)->correctiveAction($action);
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Computed, not stored -- true once past due_date and not yet completed/verified/cancelled. */
    public function getIsOverdueAttribute(): bool
    {
        if (! $this->due_date || in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_VERIFIED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        return $this->due_date->isPast();
    }
}
