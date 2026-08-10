<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Acceleration Part 4 (Contractor Management). See the owning migration's own doc comment on why this is separate from Vendor. */
class Contractor extends Model
{
    use SoftDeletes;

    public const APPROVAL_STATUSES = ['pending', 'approved', 'rejected'];

    public const STATUSES = ['active', 'inactive', 'suspended'];

    protected $fillable = ['code', 'company_id', 'company_name', 'address', 'pic_name', 'pic_contact', 'approval_status', 'status', 'notes'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function documents()
    {
        return $this->hasMany(ContractorDocument::class);
    }

    public function workers()
    {
        return $this->hasMany(ContractorWorker::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')->orderBy('company_name');
    }

    public static function generateCode(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('contractor', $companyId);
    }
}
