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

    /* ------------------------------------------------------------------
     * v2.121.0 -- WHICH COMPLETENESS REQUIREMENT THIS TYPE SATISFIES.
     *
     * Read by `PpeComplianceService`, never by matching a display name:
     * ADR 050 lets a customer rename a type through an override, and a
     * safety status that depends on spelling is worse than no status.
     * ------------------------------------------------------------------ */

    /** Work Uniform, Coverall, or whatever this customer issues to wear. */
    public const ROLE_WORK_CLOTHING = 'work_clothing';

    public const ROLE_SAFETY_SHOES = 'safety_shoes';

    public const ROLE_SAFETY_HELMET = 'safety_helmet';

    /**
     * EXPLICITLY not required, as opposed to never classified.
     *
     * The difference matters only for an override. `null` on an override
     * means "nobody has classified this row", which INHERITS the
     * template's role so that adapting Safety Shoes to six months cannot
     * quietly remove footwear from the rule. A customer who genuinely means
     * "this is additional equipment for us" needs a way to say so, and
     * `null` cannot carry both meanings -- so they say it with this.
     */
    public const ROLE_ADDITIONAL = 'additional';

    /**
     * The three REQUIRED groups, in the order a human reads them.
     *
     * `ROLE_ADDITIONAL` and `null` are not listed: neither is a
     * requirement. Safety Glasses, Harness and Headlamp ship unclassified.
     */
    public const COMPLIANCE_ROLES = [
        self::ROLE_WORK_CLOTHING,
        self::ROLE_SAFETY_SHOES,
        self::ROLE_SAFETY_HELMET,
    ];

    /** Everything a request may set, requirements plus the explicit opt-out. */
    public const ASSIGNABLE_COMPLIANCE_ROLES = [
        self::ROLE_WORK_CLOTHING,
        self::ROLE_SAFETY_SHOES,
        self::ROLE_SAFETY_HELMET,
        self::ROLE_ADDITIONAL,
    ];

    /** English, because these are labels -- see CLAUDE.md's language rule. */
    public const COMPLIANCE_ROLE_LABELS = [
        self::ROLE_WORK_CLOTHING => 'Work Clothing',
        self::ROLE_SAFETY_SHOES => 'Safety Shoes',
        self::ROLE_SAFETY_HELMET => 'Safety Helmet',
        self::ROLE_ADDITIONAL => 'Additional equipment',
    ];

    /**
     * This type's role, falling back to the template it replaces.
     *
     * An override exists to change an interval or a name (ADR 050); it does
     * not exist to silently drop a requirement. So an override that has not
     * been given a role of its own INHERITS the built-in's, which means
     * adapting "Safety Shoes" to six months cannot quietly remove footwear
     * from the completeness rule. Setting the role on the override
     * explicitly still wins, because reclassifying is a deliberate act.
     *
     * Reads the already-loaded relation when it is there, so a caller that
     * eager-loads `sourceDefault` pays one query rather than one per row.
     */
    public function effectiveComplianceRole(): ?string
    {
        if ($this->compliance_role === self::ROLE_ADDITIONAL) {
            return null; // said explicitly: not a requirement here.
        }

        if ($this->compliance_role !== null) {
            return $this->compliance_role;
        }

        if ($this->source_default_id === null) {
            return null;
        }

        $template = $this->relationLoaded('sourceDefault')
            ? $this->getRelation('sourceDefault')
            : static::withoutGlobalScopes()->find($this->source_default_id);

        $inherited = $template?->compliance_role;

        return $inherited === self::ROLE_ADDITIONAL ? null : $inherited;
    }

    /** The built-in this row replaces for its company, if it is an override. */
    public function sourceDefault()
    {
        return $this->belongsTo(self::class, 'source_default_id');
    }

    /**
     * True for a shared catalogue template. The template itself is not
     * rewritten; administrators edit its effective value through a
     * company-owned override, so each Operating Unit can customize it.
     */
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

    protected $fillable = ['company_id', 'source_default_id', 'name', 'compliance_role', 'replacement_interval_months', 'is_active'];

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
