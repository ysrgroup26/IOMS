<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream B1. Mirrors DailyReportPhoto exactly. */
class SafetyObservationPhoto extends Model
{
    use HasSecureDocument;
    protected $fillable = ['safety_observation_id', 'photo_path', 'caption'];

    protected $appends = ['url'];

    public function safetyObservation()
    {
        return $this->belongsTo(SafetyObservation::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->secureDocumentUrl();
    }

    /** v2.38.0 (Master Audit): see App\Concerns\HasSecureDocument. */
    public function secureDocumentPathColumn(): string
    {
        return 'photo_path';
    }

    /** v2.38.0 (Master Audit): no company_id of its own -- ownership resolves through its parent. */
    public function secureDocumentOwnerCompanyId(): ?int
    {
        return $this->safetyObservation?->company_id;
    }
}
