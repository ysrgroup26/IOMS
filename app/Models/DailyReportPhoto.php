<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

class DailyReportPhoto extends Model
{
    use HasSecureDocument;
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
        return $this->dailyReport?->project?->company_id;
    }
}
