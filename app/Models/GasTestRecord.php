<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream B7 (Gas Test). Child of PermitToWork -- see its own migration's doc comment. */
class GasTestRecord extends Model
{
    public const RESULTS = ['pass', 'fail'];

    protected $fillable = [
        'permit_to_work_id', 'company_id', 'tested_at', 'tested_by',
        'o2_level', 'lel_level', 'h2s_level', 'co_level', 'result', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'tested_at' => 'datetime',
            'o2_level' => 'float',
            'lel_level' => 'float',
            'h2s_level' => 'float',
            'co_level' => 'float',
        ];
    }

    public function permitToWork()
    {
        return $this->belongsTo(PermitToWork::class);
    }

    public function tester()
    {
        return $this->belongsTo(User::class, 'tested_by');
    }
}
