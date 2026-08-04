<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyReportActivity extends Model
{
    protected $fillable = ['daily_report_id', 'description', 'sort_order'];

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }
}
