<?php

use App\Models\Package;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.83.0 -- THE MANAGEMENT WORKSPACE, AND WHERE A PERSON STARTS.
     *
     * Two additive changes, both of which exist because v2.82.0 sold
     * something the product could not yet deliver.
     *
     * 1. `workspaces.management`. ADR 043 closed with an open question:
     *    the approved Business scope reads "HSE + People/HRD +
     *    Logistics/Warehouse + Management", and the fourth name had no
     *    IOMS department to map to. Granting a workspace key that does
     *    not exist is the v2.58.0 empty-sidebar defect, so nothing was
     *    invented at the time. The answer is to BUILD the capability
     *    rather than to drop the word, and this row is the catalogue half
     *    of that: a real department-tier workspace, granted by
     *    config/plans.php to Business and above.
     *
     *    EXISTING TENANTS ARE BACKFILLED. A Business customer provisioned
     *    before this release holds explicit `tenant_workspaces` rows, and
     *    `EntitlementService::grantedWorkspaceKeys()` reads an explicit
     *    grant list as EXHAUSTIVE -- so without this backfill every
     *    already-paying Business tenant would be denied the workspace
     *    their plan now includes. The grant is derived from the tenant's
     *    own package through `defaultWorkspaceKeys()`, never assumed, so
     *    a Starter tenant is untouched.
     *
     * 2. `users.workspace_focus`. Where a person STARTS, which is not the
     *    same question as what they may reach. It is deliberately
     *    nullable, and null means "All Workspaces" -- every existing
     *    account therefore keeps exactly today's behaviour. Nothing in
     *    the authorization chain reads this column; see ADR 044.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'workspace_focus')) {
            Schema::table('users', function (Blueprint $table) {
                // Not a foreign key to `workspaces`: the column holds a
                // workspace KEY (the same vocabulary config/departments.php
                // and `users.department_key` already use), and a focus
                // pointing at a workspace that was later deactivated must
                // degrade to "All Workspaces", not block the row.
                $table->string('workspace_focus')->nullable()->after('department_key');
            });
        }

        // The catalogue row. `updateOrCreate` so a re-run after a partial
        // failure cannot duplicate it.
        Workspace::updateOrCreate(
            ['key' => 'management'],
            [
                'label' => 'Management',
                'icon' => 'TrendingUp',
                'tier' => Workspace::TIER_DEPARTMENT,
                'is_core' => false,
                'is_active' => true,
                // Directly after Warehouse, before the future departments
                // -- it reads the operational ladder it summarises.
                'sort_order' => 6,
            ]
        );

        $this->backfillGrants();
    }

    /**
     * Grant `management` to every tenant whose OWN package already
     * includes it, and only to those. Tenants with no explicit grant rows
     * are left alone: an empty grant list means "not yet restricted"
     * everywhere else in this codebase, and writing one row would turn
     * that tenant into an explicitly-restricted one holding a single
     * workspace.
     */
    private function backfillGrants(): void
    {
        if (! Schema::hasTable('tenant_workspaces') || ! Schema::hasTable('packages')) {
            return;
        }

        $workspaceId = Workspace::where('key', 'management')->value('id');

        if (! $workspaceId) {
            return;
        }

        Tenant::query()->with('subscription.package')->chunkById(100, function ($tenants) use ($workspaceId) {
            foreach ($tenants as $tenant) {
                $package = $tenant->subscription?->package;

                if (! $package instanceof Package) {
                    continue;
                }

                if (! in_array('management', $package->defaultWorkspaceKeys(), true)) {
                    continue;
                }

                // Only a tenant that is ALREADY explicitly provisioned.
                if (! DB::table('tenant_workspaces')->where('tenant_id', $tenant->id)->exists()) {
                    continue;
                }

                DB::table('tenant_workspaces')->insertOrIgnore([
                    'tenant_id' => $tenant->id,
                    'workspace_id' => $workspaceId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        $workspaceId = Workspace::where('key', 'management')->value('id');

        if ($workspaceId && Schema::hasTable('tenant_workspaces')) {
            DB::table('tenant_workspaces')->where('workspace_id', $workspaceId)->delete();
        }

        Workspace::where('key', 'management')->delete();

        if (Schema::hasColumn('users', 'workspace_focus')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('workspace_focus');
            });
        }
    }
};
