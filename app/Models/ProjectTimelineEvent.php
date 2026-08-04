<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Powers the Project Detail Timeline. Today only "project_created" events
 * are written (see ProjectController@store). The polymorphic subject_type/
 * subject_id columns are ready for V2 modules (Inspection, Gas Test, Permit,
 * Daily Report, Waste, etc.) to write their own timeline rows without any
 * schema change -- see record() below, which any future module controller
 * can call the same way ActivityLog::record() is used elsewhere.
 */
class ProjectTimelineEvent extends Model
{
    protected $fillable = [
        'project_id',
        'event_type',
        'title',
        'description',
        'event_date',
        'subject_type',
        'subject_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['event_date' => 'date'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function record(
        int $projectId,
        string $eventType,
        string $title,
        ?string $description = null,
        ?\DateTimeInterface $eventDate = null,
        ?Model $subject = null
    ): self {
        return static::create([
            'project_id' => $projectId,
            'event_type' => $eventType,
            'title' => $title,
            'description' => $description,
            'event_date' => $eventDate ?? now(),
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'created_by' => auth()->id(),
        ]);
    }
}
