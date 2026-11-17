<?php

namespace App\Console\Commands;

use App\Services\TenantArchiveService;
use Illuminate\Console\Command;

/** ADR 088 -- daily: archive organizations dormant for a year. Nothing is deleted. */
class ArchiveDormantTenants extends Command
{
    protected $signature = 'tenants:archive-dormant';

    protected $description = 'Archive (hide, keep intact) organizations lapsed and untouched for a year (ADR 088).';

    public function handle(TenantArchiveService $archive): int
    {
        $this->info('archived='.$archive->archiveDormant());

        return self::SUCCESS;
    }
}
