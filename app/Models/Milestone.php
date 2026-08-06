<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Project Management's first real module beyond Projects/Daily Reports (v1.10.0). */
class Milestone extends Model
{
    public const STATUSES = ['pending', 'in_progress', 'completed', 'delayed'];

    protected $appends = ['is_overdue'];

    protected $fillable = [
        'milestone_number',
        'project_id',
        'title',
        'description',
        'target_date',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Computed, not stored -- the same "derive rather than duplicate a status" convention Task::is_overdue already uses. A milestone already marked completed is never considered overdue regardless of its target date. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status !== 'completed' && $this->target_date->isPast();
    }
}
