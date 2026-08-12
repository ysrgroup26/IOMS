<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 4, Workstream B7 (Gas Test), extended v1.10.9 (HSE Domain
 * Hardening). Child of PermitToWork -- see its own migration's doc
 * comment. `location` and `stage` (added 2026_08_23_100109) let a single
 * PTW carry multiple, individually meaningful readings over its
 * duration/scope -- e.g. Initial at "Tank TK-001" 08:00, Re-Test at the
 * same location 10:30, Final 16:00 -- without overwriting any prior one.
 */
class GasTestRecord extends Model
{
    public const RESULTS = ['pass', 'fail'];

    public const STAGE_INITIAL = 'initial';

    public const STAGE_RE_TEST = 're_test';

    public const STAGE_FINAL = 'final';

    /** Kept in operational sequence order (not alphabetical) -- this is also the order Select options render in. */
    public const STAGES = [self::STAGE_INITIAL, self::STAGE_RE_TEST, self::STAGE_FINAL];

    public const STAGE_LABELS = [
        self::STAGE_INITIAL => 'Initial',
        self::STAGE_RE_TEST => 'Re-Test',
        self::STAGE_FINAL => 'Final',
    ];

    protected $fillable = [
        'permit_to_work_id', 'company_id', 'location', 'tested_at', 'stage', 'tested_by',
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

    public function stageLabel(): string
    {
        return self::STAGE_LABELS[$this->stage] ?? ucfirst((string) $this->stage);
    }
}
