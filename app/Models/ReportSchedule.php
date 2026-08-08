<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Report Center, Task #65). See the creating migration's
 * doc comment for why this notifies rather than emails.
 */
class ReportSchedule extends Model
{
    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'user_id',
        'dataset_key',
        'format',
        'frequency',
        'is_active',
        'last_run_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function computeNextRunAt(): \Illuminate\Support\Carbon
    {
        return match ($this->frequency) {
            self::FREQUENCY_DAILY => now()->addDay(),
            self::FREQUENCY_WEEKLY => now()->addWeek(),
            self::FREQUENCY_MONTHLY => now()->addMonth(),
            default => now()->addDay(),
        };
    }
}
