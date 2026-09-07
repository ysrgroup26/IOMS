<?php

namespace App\Models;

use App\Models\Scopes\CompanyAuthorizationScope;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * AN OPERATING UNIT -- the third level of IOMS -> Organization ->
 * Operating Unit -> Department.
 *
 * A yard, a site, a division: GAJ and MTC are two operating units of one
 * organization, not two customers and not two subscriptions. The class
 * and table keep the name `Company` because every FK, scope and
 * controller in the schema is built on `company_id`; the product
 * vocabulary is Operating Unit and that is what every human-readable
 * surface says (v2.54.0).
 *
 * A LEGAL ENTITY IS AN ATTRIBUTE HERE, NOT A LEVEL ABOVE. Most customers
 * have one registered company and several operating units. The few
 * Enterprise customers with several registered entities record which one
 * a unit trades as in `legal_entity_name`/`legal_entity_registration`,
 * which documents can print. Nothing queries by it, and nothing is
 * scoped by it -- ownership of a record is `company_id` and only
 * `company_id`, so there is exactly one answer to "who does this belong
 * to".
 *
 * Milestone 2 (Tenancy Foundation): belongsTo Tenant, and every query
 * against this model is automatically scoped to the current tenant via
 * TenantScope -- see that class's own doc comment for why this is the
 * ONE place isolation is enforced, not a tenant_id column repeated on
 * every downstream table. `Department`, `Position`, `Employee`, and
 * everything else that already scopes through `company_id` inherits
 * isolation transitively: they can only ever point at a Company row this
 * scope allowed through in the first place.
 */
class Company extends Model
{
    protected $fillable = [
        'tenant_id', 'name', 'code', 'is_active',
        'legal_entity_name', 'legal_entity_registration',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
        // v2.53.0: narrows further to the companies THIS user is
        // authorized for, when any grants exist. Only ever removes rows --
        // see CompanyAuthorizationScope for why "no grants" means "all".
        static::addGlobalScope(new CompanyAuthorizationScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Users explicitly authorized for this operating unit (v2.53.0).
     *
     * An empty set here does NOT mean "nobody" -- authorization is
     * recorded per USER, and a user with no grants at all reaches every
     * operating unit in their organization. See CompanyAuthorizationScope.
     */
    public function authorizedUsers()
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The registered company this operating unit trades as, falling back
     * to the unit's own name when the organization has only one entity --
     * which is the ordinary case. Used on documents, never for scoping.
     */
    public function legalEntityName(): string
    {
        return $this->legal_entity_name ?: $this->name;
    }

    public function departments()
    {
        return $this->hasMany(Department::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function reportConfigurations()
    {
        return $this->hasMany(ReportConfiguration::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
