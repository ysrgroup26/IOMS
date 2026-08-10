<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 5 (Visitor Management). See the owning migration's own doc comment. */
class Visitor extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_CHECKED_OUT = 'checked_out';

    protected $fillable = [
        'visitor_number', 'company_id', 'name', 'visitor_company', 'purpose', 'host_employee_id',
        'visit_date', 'contact_phone', 'contact_email', 'status', 'hse_induction_completed',
        'checked_in_at', 'checked_out_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'hse_induction_completed' => 'boolean',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function hostEmployee()
    {
        return $this->belongsTo(Employee::class, 'host_employee_id');
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('visitor', $companyId);
    }
}
