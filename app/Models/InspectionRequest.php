<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 3 (QC Foundation). See the owning migration's own doc comment. */
class InspectionRequest extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const RESULT_PASSED = 'passed';

    public const RESULT_FAILED = 'failed';

    protected $fillable = [
        'inspection_number', 'company_id', 'project_id', 'project_activity_id', 'inspector_id',
        'inspection_date', 'status', 'result', 'notes',
    ];

    protected function casts(): array
    {
        return ['inspection_date' => 'date'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function activity()
    {
        return $this->belongsTo(ProjectActivity::class, 'project_activity_id');
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function evidence()
    {
        return $this->hasMany(InspectionEvidence::class);
    }

    /** An inspection that fails is a real, common NCR source -- see NcrController::createFromInspection(). */
    public function ncrs()
    {
        return Ncr::where('source_type', self::class)->where('source_id', $this->id);
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('inspection_request', $companyId);
    }
}
