<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpeType extends Model
{
    protected $fillable = ['name', 'replacement_interval_months', 'is_active'];

    protected function casts(): array
    {
        return [
            'replacement_interval_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * v2.63.0 -- CAREFUL: `employee_ppe` IS TENANT-SCOPED, THIS TABLE IS NOT.
     *
     * `ppe_types` is deliberately installation-wide reference data -- six
     * generic rows ("Safety Helmet", "Coverall") with a replacement
     * interval, holding nothing that identifies a customer. It carries no
     * `company_id` and no isolation scope, and the audit recorded that as
     * an intentional decision rather than an oversight.
     *
     * But this relation crosses INTO scoped data, so it answers a
     * per-tenant question by default. A caller asking "is anyone anywhere
     * using this type" -- deleting a shared row, for instance -- must say
     * `->withoutGlobalScopes()` explicitly. See PpeTypeController::destroy().
     */
    public function assignments()
    {
        return $this->hasMany(EmployeePpe::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    /**
     * True for request-based equipment (e.g. Harness, Headlamp) that has
     * no fixed replacement schedule but must still be recorded in history.
     */
    public function isRequestBased(): bool
    {
        return is_null($this->replacement_interval_months);
    }
}
