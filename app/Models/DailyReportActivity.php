<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyThrough;

use Illuminate\Database\Eloquent\Model;

class DailyReportActivity extends Model
{
    use BelongsToCompanyThrough;

    /** Ownership resolves through this relation -- see BelongsToCompanyThrough. */
    protected string $companyOwnerRelation = 'dailyReport';

    protected $fillable = ['daily_report_id', 'description', 'sort_order'];

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }
}
