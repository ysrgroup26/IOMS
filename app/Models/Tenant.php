<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 2 (Tenancy Foundation). The real SaaS isolation boundary --
 * one Tenant is one paying customer organization.
 *
 * v2.54.0 gives that its product name: a Tenant is an ORGANIZATION, the
 * second level of IOMS -> Organization -> Operating Unit -> Department.
 * `Company` is an OPERATING UNIT within it (GAJ, MTC), which is the
 * meaning it has always had -- ADR-008 called it "an internal business
 * unit WITHIN one Tenant, not a tenant itself" from the start. Only the
 * vocabulary is new, and it is now used consistently everywhere a human
 * reads it. The class names stay: renaming Tenant/Company would touch
 * every FK, controller and scope in the schema to buy a word.
 *
 * See docs/ADR/008-tenancy-foundation.md for why isolation is enforced
 * via `companies.tenant_id` + Company's own global scope rather than a
 * parallel tenant_id on every downstream table, and
 * docs/ARCHITECTURE.md 's Organizational Model section for the hierarchy.
 */
class Tenant extends Model
{
    use SoftDeletes;

    /*
     |-------------------------------------------------------------------
     | ACCOUNT STATUS -- the platform operator's switch (v2.70.0)
     |-------------------------------------------------------------------
     | Whether this organization's ACCOUNT is open. Distinct from the
     | subscription, which is the commercial arrangement: an account can
     | be switched off for abuse or a legal reason while the subscription
     | is paid up, and a paid-up account can still lapse commercially.
     |
     | `expired` is gone. It was never written by anything, and expiry is
     | not an account decision -- it is where the SUBSCRIPTION sits in
     | time, which Subscription::lifecycleState() derives from the dates.
     | Keeping a second, stored, never-updated copy of that here could
     | only ever disagree with it.
     |
     | This is now enforced: EntitlementService::tenantIsUsable() reads
     | isActive(). Before v2.70.0 the Platform Admin UI wrote this column
     | and NOTHING read it, so suspending a customer did nothing at all.
     */
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_SUSPENDED];

    protected $fillable = ['name', 'slug', 'status', 'trial_ends_at', 'is_demo'];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'is_demo' => 'boolean',
        ];
    }

    /**
     * The operating units of this organization.
     *
     * `operatingUnits()` is the name the product uses; `companies()` is
     * kept as the relation the rest of the codebase already calls, so
     * this is one relationship with a readable alias, not a second
     * mechanism. Neither applies CompanyAuthorizationScope-free access:
     * both go through the Company model and therefore through every
     * scope on it.
     */
    public function companies()
    {
        return $this->hasMany(Company::class);
    }

    public function operatingUnits()
    {
        return $this->companies();
    }

    /** The organization's display name -- what a header, invoice or document letterhead calls this customer. */
    public function organizationName(): string
    {
        return $this->name;
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    /**
     * Milestone 3 (UAT #4/#5). Modules/Workspaces the Platform has
     * granted this tenant -- the ceiling on what this tenant's own
     * Company Admin can enable via the pre-existing `enabled_modules`
     * CompanySetting toggle. See the granting migration's own doc
     * comment for the full reasoning.
     */
    public function modules()
    {
        return $this->belongsToMany(Module::class, 'tenant_modules');
    }

    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class, 'tenant_workspaces');
    }

    /**
     * v2.53.0 -- the IOMS Sandbox tenant.
     *
     * A REAL tenant with real isolation, not a special code path, so it
     * inherits every boundary the product already enforces. The flag
     * exists so the application can refuse writes and refuse to treat
     * demo data as a customer's -- it never grants anything.
     */
    public function isDemo(): bool
    {
        return (bool) $this->is_demo;
    }

    /**
     * Whether the ACCOUNT is open. Says nothing about whether the
     * subscription is paid -- that is Subscription::lifecycleState().
     * Read by EntitlementService::tenantIsUsable() on every gated request.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE || $this->status === self::STATUS_TRIAL;
    }
}
