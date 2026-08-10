<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Acceleration Part 2. See the owning migration's own doc comment. */
class WorkOrder extends Model
{
    use HasWorkflow, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_PREVENTIVE = 'preventive';

    public const TYPE_CORRECTIVE = 'corrective';

    public const TYPES = [self::TYPE_PREVENTIVE, self::TYPE_CORRECTIVE];

    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_SCHEDULED, self::STATUS_CANCELLED],
        self::STATUS_SCHEDULED => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'wo_number', 'company_id', 'asset_id', 'maintenance_request_id', 'maintenance_type',
        'technician_id', 'planned_date', 'actual_date', 'work_description', 'completion_notes',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_date' => 'date',
            'actual_date' => 'date',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function maintenanceRequest()
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    public function technician()
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function spareParts()
    {
        return $this->hasMany(WorkOrderSparePart::class);
    }

    /** Same 'notify whoever created it' shape as every other module -- created_by/creator() already match HasWorkflow's own convention. */
    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('work_order', $companyId);
    }
}
