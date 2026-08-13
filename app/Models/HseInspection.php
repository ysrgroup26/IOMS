<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Workstream B2 (HSE Inspection). See the owning migration's own doc comment. */
class HseInspection extends Model
{
    use SoftDeletes;

    // v1.11.1 (HSE Domain Hardening II, Part 9): 'lsa' (Life Saving
    // Appliances) and 'ffa' (Fire Fighting Appliances) added as explicit
    // categories -- 'fire_safety' is kept unchanged (not renamed/removed)
    // so every existing inspection record stays valid; 'ffa' is the new,
    // more precise term going forward for fire-fighting-equipment-
    // specific inspections. `checklist_items` (JSON, see below) is
    // already a fully configurable checklist per inspection -- this
    // constant only controls the CATEGORY label, not the items within
    // it, so LSA/FFA/PPE checklists don't require any schema change to
    // support (confirmed via audit before adding anything -- see
    // docs/MODULES.md).
    public const TYPES = ['general', 'ppe', 'lsa', 'ffa', 'fire_safety', 'electrical', 'scaffolding', 'housekeeping', 'equipment'];

    public const RESULTS = ['pass', 'fail'];

    protected $fillable = [
        'inspection_number', 'company_id', 'project_id', 'inspection_type', 'location',
        'inspection_date', 'inspector_id', 'checklist_items', 'overall_result', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date',
            'checklist_items' => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    /** Reusable CAPA -- see CorrectiveAction's own doc comment. */
    public function correctiveActions()
    {
        return $this->morphMany(CorrectiveAction::class, 'source');
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('hse_inspection', $companyId);
    }
}
