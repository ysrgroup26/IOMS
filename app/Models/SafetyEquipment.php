<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Milestone 4, Workstream B10. See the owning migration's own doc comment. */
class SafetyEquipment extends Model
{
    use BelongsToCompany;

    public const TYPES = ['fire_extinguisher', 'safety_shower', 'eyewash_station', 'emergency_alarm', 'spill_kit', 'other'];

    public const STATUSES = ['active', 'out_of_service'];

    /**
     * v2.133.0 -- WHERE A UNIT STANDS, derived and never stored.
     *
     * Ordered worst first: a unit has exactly one standing, the worst that
     * applies. It is computed from the dates the unit's TYPE says it tracks
     * (ADR 074) and from its latest inspection result, so nothing here can
     * drift from the records it summarises, and no state is invented for a
     * unit that has no data: a unit nobody has scheduled is NOT SCHEDULED,
     * never "compliant".
     */
    public const COMPLIANCE_STATES = [
        'out_of_service', 'action_required', 'expired', 'overdue', 'due_soon', 'not_scheduled', 'compliant',
    ];

    /** How far ahead a date counts as "due soon". */
    public const DUE_SOON_DAYS = 30;

    /** The identifier prefix a unit gets when its type declares none. */
    public const DEFAULT_CODE_PREFIX = 'EQ';

    protected $fillable = [
        'company_id', 'asset_id', 'name', 'type', 'location', 'serial_number',
        'last_inspection_date', 'next_inspection_due', 'status', 'notes',
        // v2.53.0 -- the REGISTER describes an actual unit, and points at
        // the MASTER that defines its type. See the
        // separate_equipment_master_from_register migration for why
        // lifecycle dates belong here while which lifecycles APPLY is a
        // property of the type.
        'equipment_type_id', 'equipment_code', 'brand', 'model', 'commissioned_at',
        'expiry_date', 'next_service_due', 'next_calibration_due',
    ];

    protected $appends = ['is_overdue'];

    protected function casts(): array
    {
        return [
            'last_inspection_date' => 'date',
            'next_inspection_due' => 'date',
            'commissioned_at' => 'date',
            'expiry_date' => 'date',
            'next_service_due' => 'date',
            'next_calibration_due' => 'date',
        ];
    }

    /** The Equipment Master entry that defines this unit's type. */
    public function equipmentType()
    {
        return $this->belongsTo(HseEquipmentType::class, 'equipment_type_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /** Milestone 4, Acceleration Part 1C -- optional link to the Asset register, additive (see the owning migration's own doc comment). */
    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    /** v1.11.1 -- real inspection history, see SafetyEquipmentInspection's own doc comment. */
    public function inspections()
    {
        return $this->hasMany(SafetyEquipmentInspection::class)->latest('inspection_date');
    }

    /** The most recent inspection, for the register's "last result" without loading the whole history. */
    public function latestInspection()
    {
        return $this->hasOne(SafetyEquipmentInspection::class)->ofMany(['inspection_date' => 'max', 'id' => 'max']);
    }

    /**
     * The lifecycle dates that APPLY to this unit, each with its own state.
     *
     * Which lifecycles apply is the type's declaration (v2.53.0). A unit not
     * yet linked to a type is judged on inspection plus whatever dates it
     * actually carries -- never on a lifecycle nobody recorded.
     *
     * @return array<int, array{key: string, date: ?string, state: string}>
     */
    public function lifecycles(?Carbon $today = null): array
    {
        $today ??= now()->startOfDay();
        $type = $this->relationLoaded('equipmentType') ? $this->equipmentType : $this->equipmentType()->first();

        $columns = [
            'inspection' => 'next_inspection_due',
            'calibration' => 'next_calibration_due',
            'service' => 'next_service_due',
            'expiry' => 'expiry_date',
        ];
        $tracked = $type
            ? $type->trackedLifecycles()
            : array_keys(array_filter($columns, fn ($column, $key) => $key === 'inspection' || $this->{$column}, ARRAY_FILTER_USE_BOTH));

        $out = [];
        foreach ($tracked as $key) {
            $date = $this->{$columns[$key]};
            $state = match (true) {
                ! $date => 'not_scheduled',
                $date->lt($today) => $key === 'expiry' ? 'expired' : 'overdue',
                $date->lte($today->copy()->addDays(self::DUE_SOON_DAYS)) => 'due_soon',
                default => 'compliant',
            };
            $out[] = ['key' => $key, 'date' => $date?->toDateString(), 'state' => $state];
        }

        return $out;
    }

    /** This unit's one standing -- the worst of COMPLIANCE_STATES that applies. */
    public function complianceState(?Carbon $today = null): string
    {
        if ($this->status === 'out_of_service') {
            return 'out_of_service';
        }

        $latest = $this->relationLoaded('latestInspection') ? $this->latestInspection : $this->latestInspection()->first();
        if ($latest && in_array($latest->result, ['fail', 'needs_action'], true)) {
            return 'action_required';
        }

        $states = array_column($this->lifecycles($today), 'state');
        foreach (['expired', 'overdue', 'due_soon', 'not_scheduled'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return 'compliant';
    }

    /** The earliest lifecycle date that is still open, for "next due" in a list. */
    public function nextDue(?Carbon $today = null): ?array
    {
        $dated = array_values(array_filter($this->lifecycles($today), fn ($l) => $l['date'] !== null));
        usort($dated, fn ($a, $b) => strcmp($a['date'], $b['date']));

        return $dated[0] ?? null;
    }

    /**
     * The next unit identifier for a company and prefix: FE-00001, EQ-00012.
     *
     * Unique within a company (two tenants may both run an FE-00001, exactly
     * like a document number). Read under a row lock inside the caller's
     * transaction so two units saved at once cannot take the same number.
     * Codes a person typed by hand are respected and simply skipped over.
     */
    public static function nextEquipmentCode(int $companyId, ?string $prefix): string
    {
        $prefix = strtoupper(trim((string) $prefix)) ?: self::DEFAULT_CODE_PREFIX;

        $max = self::query()
            ->where('company_id', $companyId)
            ->where('equipment_code', 'like', $prefix.'-%')
            ->lockForUpdate()
            ->pluck('equipment_code')
            ->map(fn ($code) => preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $code, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return sprintf('%s-%05d', $prefix, $max + 1);
    }

    /** Computed, not stored -- true once past next_inspection_due and still in service. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'active' && $this->next_inspection_due && $this->next_inspection_due->isPast();
    }
}
