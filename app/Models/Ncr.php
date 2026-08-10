<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Acceleration Part 3 (NCR). See the owning migration's own doc comment on why corrective action reuses CorrectiveAction rather than a new field. */
class Ncr extends Model
{
    use SoftDeletes;

    public const SEVERITIES = ['minor', 'major', 'critical'];

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_CLOSED];

    protected $fillable = [
        'ncr_number', 'company_id', 'source_type', 'source_id', 'description', 'severity',
        'responsible_party', 'status', 'raised_by', 'raised_date',
    ];

    protected function casts(): array
    {
        return ['raised_date' => 'date'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function raiser()
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    /** Reuses the SAME polymorphic CorrectiveAction entity Safety Observation/HSE Inspection/Incident already use -- see this model's own migration doc comment. */
    public function correctiveActions()
    {
        return $this->morphMany(CorrectiveAction::class, 'source');
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('ncr', $companyId);
    }
}
