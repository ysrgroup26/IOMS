<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 4, Workstream B8 (LOTO). Deliberately simpler than
 * PermitToWork -- no HasWorkflow state machine, just "isolated" ->
 * "removed" (a single boolean-ish transition tracked via
 * removed_by/removed_at), matching how lockout/tagout is actually
 * operated (apply, then release once work is done) rather than a
 * multi-step approval document.
 */
class LotoRecord extends Model
{
    use SoftDeletes;

    public const STATUS_ISOLATED = 'isolated';

    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'loto_number', 'company_id', 'permit_to_work_id', 'equipment_name', 'isolation_points',
        'applied_by', 'applied_at', 'removed_by', 'removed_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'isolation_points' => 'array',
            'applied_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function permitToWork()
    {
        return $this->belongsTo(PermitToWork::class);
    }

    public function applier()
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function remover()
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('loto', $companyId);
    }
}
