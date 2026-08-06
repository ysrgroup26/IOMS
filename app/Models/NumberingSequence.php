<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Milestone 3 (Numbering Engine). The runtime counter row --
 * `App\Services\NumberGeneratorService` is the only class that should
 * ever write to this table (always inside a locked transaction). See
 * the creating migration's doc comment for the concurrency design.
 */
class NumberingSequence extends Model
{
    protected $fillable = [
        'company_id',
        'module_key',
        'period_key',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'last_number' => 'integer',
        ];
    }
}
