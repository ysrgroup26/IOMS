<?php

namespace App\Models;

use App\Concerns\HasSecureDocument;
use Illuminate\Database\Eloquent\Model;

class PpeReplacementRequestItem extends Model
{
    use HasSecureDocument;
    protected $fillable = [
        'ppe_replacement_request_id',
        'employee_ppe_id',
        'project_id',
        'quantity',
        'documentation_photo_path',
        'remarks',
    ];

    public function replacementRequest()
    {
        return $this->belongsTo(PpeReplacementRequest::class, 'ppe_replacement_request_id');
    }

    public function employeePpe()
    {
        return $this->belongsTo(EmployeePpe::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function getDocumentationPhotoUrlAttribute(): ?string
    {
        return $this->documentation_photo_path ? $this->secureDocumentUrl() : null;
    }

    /** v2.38.0 (Master Audit): see App\Concerns\HasSecureDocument. */
    public function secureDocumentPathColumn(): string
    {
        return 'documentation_photo_path';
    }

    /** v2.38.0 (Master Audit): no company_id of its own -- ownership resolves through its parent. */
    public function secureDocumentOwnerCompanyId(): ?int
    {
        return $this->replacementRequest?->company_id;
    }
}
