<?php

use App\Models\Package;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.84.0 -- FOUR OPERATIONAL WORKSPACES, AND THE NAMES THEY ARE SOLD AS.
     *
     * No schema changes. This is a DATA migration against the `workspaces`
     * catalogue and the per-tenant grants, and it exists because both are
     * read at runtime: a label in that table OVERRIDES the one in
     * `resources/js/lib/workspaces.js` (see `applyCatalog()`), and a grant row
     * is what `EntitlementService` treats as the tenant's real scope. Shipping
     * the code change alone would leave every existing install showing "Human
     * Resources" and still granting departments the product no longer sells.
     *
     * The same lesson `CONVENTIONS.md` records from v2.51.0, in the other
     * table: `db:seed` does not re-run on an existing deployment, so a
     * catalogue change belongs in a migration.
     *
     * TWO THINGS HAPPEN HERE.
     *
     * 1. THE LABELS BECOME THE PRODUCT NAMES. People / HRD, Logistics /
     *    Warehouse, Admin Space. A tenant that renamed a workspace for itself
     *    is deliberately NOT overwritten -- a customer's own wording outranks
     *    ours, and this only corrects rows still carrying the default.
     *
     * 2. RETIRED DEPARTMENTS LOSE THEIR GRANTS. Project Management,
     *    Procurement, Asset Management, Maintenance, Quality Control, Finance
     *    and the standalone Warehouse shell are no longer customer-facing, and
     *    no plan grants them (config/plans.php). A tenant provisioned before
     *    this release still HOLDS those grant rows, and a grant row is what
     *    the entitlement layer reads -- so without this, an existing Business
     *    or Enterprise customer would keep reaching departments the product
     *    has withdrawn, while a new one would not. Two customers on one plan
     *    with different products is the outcome this prevents.
     *
     * NOTHING IS DESTROYED. The catalogue rows stay (a workspace row is not
     * tenant data), and every route, controller, model, table and record
     * behind those departments is untouched. What is removed is an
     * ENTITLEMENT, and `down()` restores it from each tenant's own package.
     */
    private const RETIRED = [
        'project-management', 'procurement', 'asset-management',
        'maintenance', 'quality-control', 'finance', 'warehouse',
    ];

    private const RENAMED = [
        'hr' => ['old' => 'Human Resources', 'new' => 'People / HRD'],
        'logistics' => ['old' => 'Logistics / PPIC', 'new' => 'Logistics / Warehouse'],
        'administration' => ['old' => 'Administration', 'new' => 'Admin Space'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        foreach (self::RENAMED as $key => $labels) {
            // Only rows still on the default label. A tenant that renamed a
            // department in Settings meant it.
            Workspace::where('key', $key)->where('label', $labels['old'])->update(['label' => $labels['new']]);
        }

        /*
         * The catalogue order is the product ladder, and it is load-bearing:
         * it decides which workspace an account lands in when it has several
         * and has chosen no focus. HSE was second behind People, so a
         * Professional customer was sent to the wrong half of their product
         * on every sign-in.
         */
        foreach (['hse' => 1, 'hr' => 2, 'logistics' => 3, 'management' => 4] as $key => $order) {
            Workspace::where('key', $key)->update(['sort_order' => $order]);
        }

        $this->withdrawRetiredGrants();
    }

    private function withdrawRetiredGrants(): void
    {
        if (! Schema::hasTable('tenant_workspaces')) {
            return;
        }

        $retiredIds = Workspace::whereIn('key', self::RETIRED)->pluck('id');

        if ($retiredIds->isEmpty()) {
            return;
        }

        DB::table('tenant_workspaces')->whereIn('workspace_id', $retiredIds)->delete();
    }

    /**
     * Restores each tenant's grants from its OWN package rather than
     * re-granting the retired list wholesale -- a down migration that hands
     * every tenant seven departments would be a worse state than the one it
     * was rolling back from.
     */
    public function down(): void
    {
        if (! Schema::hasTable('workspaces')) {
            return;
        }

        foreach (self::RENAMED as $key => $labels) {
            Workspace::where('key', $key)->where('label', $labels['new'])->update(['label' => $labels['old']]);
        }

        if (! Schema::hasTable('tenant_workspaces')) {
            return;
        }

        Tenant::query()->with('subscription.package')->chunkById(100, function ($tenants) {
            foreach ($tenants as $tenant) {
                $package = $tenant->subscription?->package;

                if (! $package instanceof Package) {
                    continue;
                }

                if (! DB::table('tenant_workspaces')->where('tenant_id', $tenant->id)->exists()) {
                    continue;
                }

                $ids = Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id');

                foreach ($ids as $id) {
                    DB::table('tenant_workspaces')->insertOrIgnore([
                        'tenant_id' => $tenant->id,
                        'workspace_id' => $id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });
    }
};
