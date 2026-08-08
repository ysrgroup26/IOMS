<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Universal Approval Engine v2). A configured multi-level/
 * parallel/conditional approval chain for one module. See the creating
 * migration's doc comment and docs/ADR/010-approval-engine-v2.md for the
 * full design -- in particular, a module with NO ApprovalFlow row falls
 * back to the legacy single-step Approval Engine, unchanged.
 */
class ApprovalFlow extends Model
{
    protected $fillable = [
        'tenant_id',
        'company_id',
        'module_key',
        'name',
        'is_active',
        'priority',
        'conditions',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'conditions' => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function steps()
    {
        return $this->hasMany(ApprovalFlowStep::class)->orderBy('step_number');
    }

    public function maxStepNumber(): int
    {
        return (int) $this->steps()->max('step_number');
    }
}
