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
        // v2.100.0 -- the actor's display identity AS IT WAS. See record().
        'actor_name',
        // v2.125.0 -- and the job title they held when they did it.
        'actor_title',
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
            /*
             * v2.100.0 -- WHO DID IT IS A FACT ABOUT THE PAST.
             *
             * This used to store only `user_id`, so every actor shown in a
             * timeline or an audit row was resolved by following that key
             * to the user's CURRENT name. Renaming one person therefore
             * rewrote their whole history, and removing them emptied it.
             *
             * The key stays, and stays authoritative for "which account".
             * This answers "what did it say at the time", which is the only
             * question an audit row can honestly answer. Null for a system
             * action with no authenticated actor, which is what the read
             * paths already render as "System".
             */
            'actor_name' => auth()->user()?->displayName(),
            /*
             * v2.125.0 -- THE TITLE THEY HELD, FROM THE COMPANY'S OWN
             * CONFIGURATION.
             *
             * The approval stamp on a controlled document has to say what
             * authority signed it, and "Super Admin" is an IOMS role, not
             * a job title -- the yard's own word is "SPV HSE", "Manager
             * Shipyard", "Site Manager", and which one is used is the
             * customer's configuration, held in `positions` and reached
             * from the account through its linked Employee.
             *
             * Captured here rather than read later for the same reason
             * `actor_name` is: a company renaming a position, or moving
             * somebody between positions, must not rewrite what a permit
             * signed last year says. Null when the account is not linked
             * to an employee or the employee holds no position, and the
             * read path then falls back to the live relation -- it never
             * substitutes the system role in this column, because that
             * would store a different KIND of fact under one name.
             */
            'actor_title' => auth()->user()?->employee?->position?->name,
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
