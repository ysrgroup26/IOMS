<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyReportPhoto extends Model
{
    protected $fillable = ['daily_report_id', 'photo_path', 'caption'];

    protected $appends = ['url'];

    public function dailyReport()
    {
        return $this->belongsTo(DailyReport::class);
    }

    /**
     * Real Eloquent accessor (v1.5.2 fix) -- previously a plain url()
     * method that Eloquent never serializes, forcing the frontend to
     * reconstruct the raw /storage/{path} string by hand instead of using
     * this.
     */
    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->photo_path);
    }
}
