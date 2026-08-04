<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PpeReplacementRequestItem extends Model
{
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
        return $this->documentation_photo_path ? asset('storage/'.$this->documentation_photo_path) : null;
    }
}
