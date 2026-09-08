<?php

namespace App\Models;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    /**
     * v2.62.0 -- THE AUDIT TRAIL HAS TWO OWNERSHIP PATHS, AND NEEDED BOTH.
     *
     * `ActivityLog::record()` copies `company_id` off the subject, so a
     * log about a record is owned exactly like the record. But a
     * tenant-level action -- signing in, changing a setting, editing a
     * user -- has no company subject and stores a null `company_id`. Those
     * rows are still owned: by the USER who performed them, and users are
     * tenant-owned.
     *
     * A plain company scope would have hidden every tenant-level entry
     * from its own Audit Log; treating null as "shared" would have shown
     * every customer's sign-ins to every other customer. So the scope
     * accepts a row on either path and nothing else.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $visibleCompanies = Company::query()->select('id');
            $tenantUsers = User::query()
                ->withoutGlobalScopes()
                ->select('id')
                ->where('tenant_id', app(CurrentTenant::class)->id() ?? -1);

            $builder->where(function (Builder $query) use ($visibleCompanies, $tenantUsers) {
                $query
                    ->whereIn('activity_logs.company_id', $visibleCompanies)
                    ->orWhere(function (Builder $inner) use ($tenantUsers) {
                        $inner
                            ->whereNull('activity_logs.company_id')
                            ->whereIn('activity_logs.user_id', $tenantUsers);
                    });
            });
        });
    }
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
