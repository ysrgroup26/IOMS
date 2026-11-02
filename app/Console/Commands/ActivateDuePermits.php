<?php

namespace App\Console\Commands;

use App\Services\PtwActivationService;
use Illuminate\Console\Command;

/**
 * v2.141.0 -- makes Active every permit on its company's approval flow whose
 * Final Verification is done and whose start time has come (ADR 083).
 * Every minute, without overlapping; idempotent per permit (row lock).
 */
class ActivateDuePermits extends Command
{
    protected $signature = 'ptw:activate-due';

    protected $description = 'Activate verified permits whose scheduled start time has been reached';

    public function handle(PtwActivationService $activation): int
    {
        $counts = $activation->activateDue();

        $this->info(collect($counts)->map(fn ($n, $outcome) => "{$outcome}: {$n}")->implode(', ') ?: 'Nothing due.');

        return self::SUCCESS;
    }
}
