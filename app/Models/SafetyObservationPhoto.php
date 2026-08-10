<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream B1. Mirrors DailyReportPhoto exactly. */
class SafetyObservationPhoto extends Model
{
    protected $fillable = ['safety_observation_id', 'photo_path', 'caption'];

    protected $appends = ['url'];

    public function safetyObservation()
    {
        return $this->belongsTo(SafetyObservation::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/'.$this->photo_path);
    }
}
