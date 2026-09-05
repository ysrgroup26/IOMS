<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Acceleration Part 3. Mirrors DailyReportPhoto's pattern. */
class InspectionEvidence extends Model
{
    use HasSecureDocument;
    protected $fillable = ['inspection_request_id', 'photo_path', 'caption'];

    protected $appends = ['url'];

    public function inspectionRequest()
    {
        return $this->belongsTo(InspectionRequest::class);
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
        return $this->inspectionRequest?->company_id;
    }
}
