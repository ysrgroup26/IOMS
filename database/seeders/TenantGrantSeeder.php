<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;

class TenantGrantSeeder extends Seeder
{
    /**
     * Milestone 3 (UAT #4/#5). The creating migration's own backfill only
     * covers tenants that exist AT MIGRATION TIME -- on a fresh install
     * (`migrate:fresh --seed`), the `tenants` table is empty when that
     * migration runs (same class of ordering issue as
     * ModuleSeeder/WorkspaceSeeder themselves, see DatabaseSeeder's own
     * doc comment). This seeder is the fresh-install equivalent: grants
     * the current tenant every module and workspace that exists, so a
     * fresh install's Default Tenant can use everything, exactly as
     * every tenant could before this grant system existed. Must run
     * after ModuleSeeder/WorkspaceSeeder (so there's something to grant)
     * and after the tenant is bound (see DatabaseSeeder's call order).
     */
    public function run(): void
    {
        $tenant = app(CurrentTenant::class)->get();

        if (! $tenant) {
            return;
        }

        $tenant->modules()->syncWithoutDetaching(Module::pluck('id'));
        $tenant->workspaces()->syncWithoutDetaching(Workspace::pluck('id'));
    }
}
