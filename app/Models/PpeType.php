<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PpeType extends Model
{
    use BelongsToCompany;

    /**
     * A NULL COMPANY IS THE SHARED BUILT-IN CATALOGUE.
     *
     * Same meaning as KpiCategory, NumberingFormat and NumberingSequence:
     * null is the default every customer starts from, a value is that
     * customer's override or addition. Effective queries hide a replaced
     * template for that company; the shared source is never changed.
     */
    public function companyScopeAllowsGlobalRows(): bool
    {
        return true;
    }

    /** True for a built-in nobody owns, and therefore nobody may edit. */
    public function isSharedDefault(): bool
    {
        return $this->company_id === null;
    }

    /** Effective catalogue: tenant-owned overrides replace a shared template in this company only. */
    public function scopeEffectiveForCompany($query, int $companyId)
    {
        $overridden = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNotNull('source_default_id')
            ->select('source_default_id');

        return $query->where(function ($catalogue) use ($companyId, $overridden) {
            $catalogue->where('company_id', $companyId)
                ->orWhere(function ($defaults) use ($overridden) {
                    $defaults->whereNull('company_id')->whereNotIn('id', $overridden);
                });
        });
    }

    protected $fillable = ['company_id', 'source_default_id', 'name', 'replacement_interval_months', 'is_active'];

    protected function casts(): array
    {
        return [
            'replacement_interval_months' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * `employee_ppe` is tenant-scoped; defaults are shared and custom PPE
     * rows are company-owned. Deletion must preserve all issuance history.
     *
     * A caller asking whether any assignment uses an owned row still must
     * say `->withoutGlobalScopes()` explicitly, since historical records
     * belonging to other authorized companies also protect it.
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
