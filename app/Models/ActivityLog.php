<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'company_id',
        'department_id',
        'module',
        'description',
        'meta',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Convenience static to record an activity from anywhere in the app.
     * Milestone 3 (Activity Center, Task #50): best-effort auto-populates
     * `company_id`/`department_id`/`module` straight off $subject's own
     * attributes when present -- every one of the 32+ existing call
     * sites keeps working unchanged (these are optional, inferred, not
     * required arguments).
     */
    public static function record(string $action, string $description, ?Model $subject = null, array $meta = []): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id' => $subject?->getKey(),
            'company_id' => $subject?->company_id ?? null,
            'department_id' => $subject?->department_id ?? null,
            'module' => $subject ? \Illuminate\Support\Str::snake(class_basename($subject)) : null,
            'description' => $description,
            'meta' => $meta,
            'ip_address' => request()?->ip(),
        ]);
    }
}
