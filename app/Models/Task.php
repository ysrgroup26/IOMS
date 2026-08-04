<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Universal Task Engine Foundation (v1.6.4). Deliberately minimal by
 * design for this version -- no comments/attachments/history/notification
 * relations exist yet (explicitly out of scope, see ROADMAP.md). Future
 * versions extending this engine should add those as separate related
 * models (TaskComment, TaskAttachment, etc.) rather than growing this
 * table further.
 */
class Task extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_IN_PROGRESS,
        self::STATUS_ON_HOLD, self::STATUS_WAITING, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    /** Statuses that count as "still needs attention" for widgets like Dashboard's Pending Tasks. */
    public const OPEN_STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_ON_HOLD, self::STATUS_WAITING];

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    public const PRIORITIES = [self::PRIORITY_LOW, self::PRIORITY_MEDIUM, self::PRIORITY_HIGH, self::PRIORITY_CRITICAL];

    protected $fillable = [
        'uuid',
        'task_number',
        'title',
        'description',
        'priority',
        'status',
        'task_type',
        'task_source',
        'related_module',
        'related_record_id',
        'company_id',
        'workspace_id',
        'assigned_user_id',
        'created_by',
        'due_date',
        'start_date',
        'completed_date',
    ];

    protected $appends = ['is_overdue'];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'start_date' => 'date',
            'completed_date' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            $task->uuid ??= (string) Str::uuid();
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_user_id', $userId);
    }

    public function scopeOpenStatus($query)
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** Computed, not stored -- "overdue" is a due-date-vs-today comparison, not a separate status column, so it can never go stale. */
    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true)
            && $this->due_date
            && $this->due_date->isPast();
    }
}
