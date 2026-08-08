<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Numbering Engine). Configuration for how a module's
 * document numbers are formatted -- see the creating migration's own
 * doc comment for the full reasoning (why company_id is nullable/
 * tenant-wide-default, why sequences stay global-scope for now).
 */
class NumberingFormat extends Model
{
    protected $fillable = [
        'tenant_id',
        'company_id',
        'module_key',
        'prefix',
        'pattern',
        'seq_padding',
        'reset_period',
    ];

    protected function casts(): array
    {
        return [
            'seq_padding' => 'integer',
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
}
