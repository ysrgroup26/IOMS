<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 2 (Tenancy Foundation): now belongsTo Tenant, and every query
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
    protected $fillable = ['tenant_id', 'name', 'code', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
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
