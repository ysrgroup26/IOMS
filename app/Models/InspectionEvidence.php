<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 3. Mirrors DailyReportPhoto's pattern. */
class InspectionEvidence extends Model
{
    protected $fillable = ['inspection_request_id', 'photo_path', 'caption'];

    protected $appends = ['url'];

    public function inspectionRequest()
    {
        return $this->belongsTo(InspectionRequest::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->photo_path);
    }
}
