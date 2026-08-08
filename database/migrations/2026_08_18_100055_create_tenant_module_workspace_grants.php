<?php

use App\Models\Module;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 3 (UAT #4/#5 -- Platform grants Modules/Workspaces,
     * Company Admin only toggles within that granted subset). Before
     * this, `modules`/`workspaces` were flat global catalogs -- EVERY
     * tenant could see and toggle-on any module/workspace that existed
     * anywhere in the system, which is wrong for a real SaaS: the
     * platform operator decides what a given tenant's PLAN includes,
     * the tenant's own admin only decides which of THOSE are currently
     * visible in their sidebar (the pre-existing `enabled_modules`
     * CompanySetting mechanism, unchanged and still doing exactly that
     * job one layer down).
     *
     * `tenant_modules`/`tenant_workspaces`: plain grant tables, presence
     * of a row = granted. Deliberately not a boolean column on
     * modules/workspaces themselves (a module can be granted to some
     * tenants and not others, so the grant is inherently a per-tenant
     * fact, not a property of the module).
     *
     * Backfill: the existing "Default Tenant" is granted EVERY module and
     * workspace that exists today, so this migration changes nothing
     * about current behavior for the one tenant that already exists — a
     * brand new tenant created after this ships starts with zero grants
     * (matching UAT's own stated expectation: a new tenant can't use
     * anything until Platform explicitly grants it).
     */
    /**
     * RC1 release audit: hardened against the same partial-failure class
     * that broke `2026_08_17_100050_create_numbering_engine_tables` --
     * two `Schema::create()` calls followed by non-trivial logic (a
     * per-tenant backfill loop) in one method means a crash partway
     * through leaves tables behind without Laravel ever recording the
     * migration as run, so a retry must not assume a clean slate.
     * `hasTable()` guards make table creation itself safe to retry, and
     * the backfill now upserts (`insertOrIgnore`, safe against the
     * `unique(['tenant_id', 'module_id'])` constraint) instead of a bare
     * `insert()`, so re-running after a partial prior run can't throw a
     * duplicate-key error on grants that already made it in.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tenant_modules')) {
            Schema::create('tenant_modules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('module_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['tenant_id', 'module_id']);
            });
        }

        if (! Schema::hasTable('tenant_workspaces')) {
            Schema::create('tenant_workspaces', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['tenant_id', 'workspace_id']);
            });
        }

        $tenantIds = Tenant::query()->pluck('id');
        $moduleIds = Module::query()->pluck('id');
        $workspaceIds = Workspace::query()->pluck('id');
        $now = now();

        foreach ($tenantIds as $tenantId) {
            $moduleRows = $moduleIds->map(fn ($moduleId) => [
                'tenant_id' => $tenantId, 'module_id' => $moduleId, 'created_at' => $now, 'updated_at' => $now,
            ])->all();
            if ($moduleRows) {
                DB::table('tenant_modules')->insertOrIgnore($moduleRows);
            }

            $workspaceRows = $workspaceIds->map(fn ($workspaceId) => [
                'tenant_id' => $tenantId, 'workspace_id' => $workspaceId, 'created_at' => $now, 'updated_at' => $now,
            ])->all();
            if ($workspaceRows) {
                DB::table('tenant_workspaces')->insertOrIgnore($workspaceRows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_workspaces');
        Schema::dropIfExists('tenant_modules');
    }
};
