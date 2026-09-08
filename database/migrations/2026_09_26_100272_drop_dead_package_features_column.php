<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.60.0 -- REMOVES THE THIRD, DEAD ANSWER TO "WHAT DOES THIS PLAN
 * INCLUDE".
 *
 * `packages.features` held a JSON list of module keys, read only by
 * `Package::hasFeature()`. A repository-wide search for that method found
 * NOT ONE call site: no controller, no middleware, no policy, no Blade
 * view, no JSX, no test. It gated nothing, and had drifted -- Professional
 * and Enterprise carried identical arrays while their real workspace
 * grants differed completely.
 *
 * Dropped rather than left dormant. A dead entitlement source is not
 * harmless: it is a plausible-looking answer sitting beside the real one,
 * and the next person needing a feature flag would reasonably have reached
 * for it, at which point IOMS would have had two disagreeing definitions
 * of a plan's scope. That is precisely the failure v2.58.0 spent a release
 * untangling one layer down, and the standing rule is one authoritative
 * entitlement model.
 *
 * The surviving chain is exactly one path:
 *
 *   config/plans.php
 *     -> Package::defaultWorkspaceKeys() / defaultModuleKeys()
 *     -> tenant_workspaces / tenant_modules
 *     -> EntitlementService::grantedWorkspaceKeys()
 *     -> route gate (EnforceTenantEntitlement) + navigation
 *
 * Reversible: `down()` restores the column, empty. The old contents are
 * not restored because they were never authoritative and restoring stale
 * data would recreate the very ambiguity this removes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('packages', 'features')) {
            return;
        }

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('packages', 'features')) {
            return;
        }

        Schema::table('packages', function (Blueprint $table) {
            $table->json('features')->nullable()->after('max_ptw_users');
        });
    }
};
