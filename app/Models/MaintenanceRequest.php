<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Models\Concerns\BelongsToCompany;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Acceleration Part 2 (Maintenance CMMS Foundation). See the owning migration's own doc comment. */
class MaintenanceRequest extends Model
{
    use BelongsToCompany, HasWorkflow, SoftDeletes;

    public const STATUS_REPORTED = 'reported';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CONVERTED_TO_WO = 'converted_to_wo';

    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected static array $transitions = [
        self::STATUS_REPORTED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_REPORTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_CONVERTED_TO_WO, self::STATUS_CANCELLED],
        self::STATUS_CONVERTED_TO_WO => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'request_number', 'company_id', 'asset_id', 'reported_by', 'problem', 'description',
        'priority', 'request_date', 'attachment_path', 'status',
    ];

    protected function casts(): array
    {
        return ['request_date' => 'date'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function workOrders()
    {
        return $this->hasMany(WorkOrder::class);
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('maintenance_request', $companyId);
    }

    /**
     * v2.124.0 -- the same number, NOT taken. For a create page that
     * tells the reader what will be saved; see
     * NumberGeneratorService::preview() for why a preview must never
     * consume a sequence.
     */
    public static function previewNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->preview('maintenance_request', $companyId);
    }

    /**
     * v2.125.0 -- where a notification about this record opens.
     *
     * Every workflow notification in this product was created with a null
     * url, so the notification menu (which navigates only when one is set)
     * had nothing to navigate to: the reader tapped it, it was marked
     * read, and nothing happened. One line per model, named explicitly
     * rather than derived from the class name, because a derived route
     * name that is subtly wrong fails silently and this is the thing that
     * was silently missing in the first place.
     *
     * @see \App\Concerns\HasWorkflow::notificationUrl()
     */
    public function notificationRouteName(): ?string
    {
        return 'maintenance-requests.show';
    }
}
