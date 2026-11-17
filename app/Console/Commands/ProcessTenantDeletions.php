<?php

namespace App\Console\Commands;

use App\Services\TenantDeletionService;
use Illuminate\Console\Command;

/** ADR 087 -- daily: send due deletion reminders and purge organizations whose 90-day hold has ended. */
class ProcessTenantDeletions extends Command
{
    protected $signature = 'tenants:process-deletions';

    protected $description = 'Send scheduled-deletion reminders and purge organizations whose hold has ended (ADR 087).';

    public function handle(TenantDeletionService $deletions): int
    {
        $r = $deletions->processDue();
        $this->info("reminded={$r['reminded']} purged={$r['purged']}");

        return self::SUCCESS;
    }
}
