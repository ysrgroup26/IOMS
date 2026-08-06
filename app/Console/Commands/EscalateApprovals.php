<?php

namespace App\Console\Commands;

use App\Services\ApprovalEngine;
use Illuminate\Console\Command;

/**
 * Milestone 3 (Universal Approval Engine v2 -- escalation). Run on a
 * schedule (see routes/console.php); delegates entirely to
 * `ApprovalEngine::checkEscalations()`, which is the single place that
 * knows how escalation actually works.
 */
class EscalateApprovals extends Command
{
    protected $signature = 'approvals:escalate';

    protected $description = 'Escalate pending approvals that have exceeded their configured escalate_after_hours window.';

    public function handle(ApprovalEngine $engine): int
    {
        $count = $engine->checkEscalations();

        $this->info("Escalated {$count} approval(s).");

        return self::SUCCESS;
    }
}
