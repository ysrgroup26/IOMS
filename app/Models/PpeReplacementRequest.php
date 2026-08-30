<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PpeReplacementRequest extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    protected $fillable = [
        'request_number',
        'request_date',
        'company_id',
        'requested_by',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function items()
    {
        return $this->hasMany(PpeReplacementRequestItem::class);
    }

    /**
     * v2.12.0 (Product Finalization pass, Part 26 -- Security). Same
     * fix, same reasoning as MaterialRequest::scopeVisibleTo() (see its
     * own updated doc comment) -- this scope predates multi-tenancy;
     * its Super Admin bypass was silently returning every tenant's PPE
     * replacement requests, not just the current tenant's. Tenant
     * boundary made unconditional before the existing narrowing.
     */
    public function scopeVisibleTo($query, $user)
    {
        $query->whereIn('company_id', Company::query()->pluck('id'));

        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where('company_id', $user->company_id);
    }

    /**
     * Milestone 3: delegates to the centralized, lock-safe Numbering
     * Engine -- see MaterialRequest::generateRequestNumber()'s doc
     * comment for why. Same PRR-{YEAR}-{00001} shape as before by default.
     */
    public static function generateRequestNumber(?int $companyId = null): string
    {
        return app(\App\Services\NumberGeneratorService::class)->generate('ppe_replacement_request', $companyId);
    }

    // Milestone 3 (Task #51) note: deliberately NOT given HasWorkflow/
    // HasApprovals in this pass. Today it's a one-shot record -- created
    // once (storeReplacementRequest()), then only ever viewed/exported,
    // with no approve/reject route or UI anywhere. Bolting on a status
    // guard with nothing to drive it would be exactly the kind of
    // half-wired feature this milestone's "no dummy, no shortcut" rule
    // warns against. A real PPE Replacement approval workflow (matching
    // MaterialRequest's pattern) is a genuine, well-scoped future
    // feature -- see docs/ADR/012-milestone-numbering.md.
}
