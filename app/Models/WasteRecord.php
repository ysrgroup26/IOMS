<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * v1.11.4 (HSE Waste Management, Part 13). One row per waste generation
 * event -- numbered via the existing NumberGeneratorService (module key
 * 'waste_record'), not a new numbering engine. `status` walks a fixed
 * lifecycle guarded by ALLOWED_TRANSITIONS (mirrors WorkOrder/
 * MaintenanceRequest's own established transition-guard pattern) --
 * invalid transitions are rejected in WasteRecordController, not left to
 * the frontend alone.
 *
 * Storage-age monitoring is fully computed, never stored/cached (same
 * "never goes stale" reasoning as SafetyEquipment::is_overdue) and is
 * DELIBERATELY an operational signal only -- `wasteType.storage_limit_days`
 * is a tenant-configured operational threshold (see WasteType's own doc
 * comment), never presented as a legal/regulatory determination.
 */
class WasteRecord extends Model
{
    public const STATUS_GENERATED = 'generated';

    public const STATUS_STORED = 'stored';

    public const STATUS_SCHEDULED_PICKUP = 'scheduled_pickup';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_DISPOSED = 'disposed';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_GENERATED, self::STATUS_STORED, self::STATUS_SCHEDULED_PICKUP,
        self::STATUS_IN_TRANSIT, self::STATUS_DISPOSED, self::STATUS_CLOSED,
    ];

    /** Mirrors WorkOrder::ALLOWED_TRANSITIONS' own shape exactly. */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_GENERATED => [self::STATUS_STORED, self::STATUS_SCHEDULED_PICKUP],
        self::STATUS_STORED => [self::STATUS_SCHEDULED_PICKUP],
        self::STATUS_SCHEDULED_PICKUP => [self::STATUS_IN_TRANSIT, self::STATUS_STORED],
        self::STATUS_IN_TRANSIT => [self::STATUS_DISPOSED],
        self::STATUS_DISPOSED => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
    ];

    /** Still physically present on-site -- the states storage-age monitoring applies to. */
    public const STORED_STATUSES = [self::STATUS_GENERATED, self::STATUS_STORED, self::STATUS_SCHEDULED_PICKUP];

    protected $fillable = [
        'record_number', 'company_id', 'waste_type_id', 'project_id', 'project_activity_id',
        'location', 'storage_location_id', 'quantity', 'unit', 'container',
        'generated_date', 'received_date', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'generated_date' => 'date',
            'received_date' => 'date',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function wasteType()
    {
        return $this->belongsTo(WasteType::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function projectActivity()
    {
        return $this->belongsTo(ProjectActivity::class);
    }

    public function storageLocation()
    {
        return $this->belongsTo(WasteStorageLocation::class, 'storage_location_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movements()
    {
        return $this->hasMany(WasteMovement::class)->latest('id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }

    protected $appends = ['days_in_storage', 'is_approaching_storage_limit', 'is_storage_overdue'];

    public function getDaysInStorageAttribute(): ?int
    {
        if (! in_array($this->status, self::STORED_STATUSES, true)) {
            return null;
        }

        $since = $this->received_date ?? $this->generated_date;

        return $since ? (int) $since->diffInDays(Carbon::today()) : null;
    }

    public function getIsApproachingStorageLimitAttribute(): bool
    {
        $limit = $this->wasteType?->storage_limit_days;
        $days = $this->days_in_storage;

        return $limit !== null && $days !== null && $days >= max(0, $limit - 7) && $days < $limit;
    }

    public function getIsStorageOverdueAttribute(): bool
    {
        $limit = $this->wasteType?->storage_limit_days;
        $days = $this->days_in_storage;

        return $limit !== null && $days !== null && $days >= $limit;
    }
}
