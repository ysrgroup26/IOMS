<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * v2.13.0 (SaaS Phase 1 -- Subscription Architecture & Entitlement
 * Enforcement). The operational counterpart to
 * `EntitlementService`'s new "ungranted tenant = fully allowed" safety
 * net (see that class's own doc comment): that net protects a tenant
 * with ZERO grant rows, but NOT a tenant that already has SOME grants
 * (e.g. from `TenantGrantSeeder`'s one-time "grant everything that
 * existed at seed time" run) that may have since fallen behind as new
 * Workspace/Module rows were added to the app. This command tops up
 * such a tenant's grants to at least its Package's own baseline
 * (`Package::defaultWorkspaceKeys()`/`defaultModuleKeys()`), so a
 * Platform Admin has a real, safe, auditable way to close that gap
 * before enabling `SAAS_ENFORCE_WORKSPACE_ENTITLEMENT` -- rather than
 * guessing whether a tenant's existing grant rows are still complete.
 *
 * Deliberately ADDITIVE ONLY (`syncWithoutDetaching`, same as
 * `TenantGrantSeeder`/`PlatformController::storeTenant()` already use)
 * -- this command can add a missing grant back up to package baseline,
 * it never removes an existing grant, so it can never be used to
 * silently downgrade a tenant's access. A tenant with NO subscription/
 * package on record is skipped with a warning rather than guessed at.
 */
class SyncTenantGrants extends Command
{
    protected $signature = 'tenants:sync-grants {tenant? : Tenant ID -- omit to process every tenant} {--dry-run : Report what would change without writing anything}';

    protected $description = "Top up a tenant's Workspace/Module grants to at least its current Package's default baseline. Additive only -- never removes an existing grant.";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->argument('tenant');

        $tenants = $tenantId
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::all();

        if ($tenants->isEmpty()) {
            $this->error($tenantId ? "No tenant found with ID \"{$tenantId}\"." : 'No tenants found.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $package = $tenant->subscription?->package;

            if (! $package) {
                $this->warn("Tenant \"{$tenant->name}\" (#{$tenant->id}): no subscription/package on record -- skipped, nothing to derive a baseline from.");

                continue;
            }

            $wantedWorkspaceIds = Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id');
            $wantedModuleIds = Module::whereIn('key', $package->defaultModuleKeys())->pluck('id');

            $currentWorkspaceIds = $tenant->workspaces()->pluck('workspaces.id');
            $currentModuleIds = $tenant->modules()->pluck('modules.id');

            $missingWorkspaceIds = $wantedWorkspaceIds->diff($currentWorkspaceIds);
            $missingModuleIds = $wantedModuleIds->diff($currentModuleIds);

            if ($missingWorkspaceIds->isEmpty() && $missingModuleIds->isEmpty()) {
                $this->info("Tenant \"{$tenant->name}\" (#{$tenant->id}, {$package->name}): already at or above package baseline. No change.");

                continue;
            }

            $this->line("Tenant \"{$tenant->name}\" (#{$tenant->id}, {$package->name}): +{$missingWorkspaceIds->count()} workspace grant(s), +{$missingModuleIds->count()} module grant(s).");

            if (! $dryRun) {
                $tenant->workspaces()->syncWithoutDetaching($missingWorkspaceIds);
                $tenant->modules()->syncWithoutDetaching($missingModuleIds);
            }
        }

        if ($dryRun) {
            $this->comment('Dry run -- no grants were written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
