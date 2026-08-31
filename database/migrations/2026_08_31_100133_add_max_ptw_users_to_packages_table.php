<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.17.0 (PTW Field Workflow Foundation + Controlled PTW Access, Part
 * 5/22/23). The SaaS entitlement representation of "how many PTW-enabled
 * user accounts can this tenant's package have at once" -- explicitly a
 * USER entitlement, not a role and not a Module/Workspace grant (a
 * tenant can have 500 employees, 100 user accounts, and only 15 of those
 * accounts PTW-enabled, all independently). Lives directly on `packages`
 * as a plain nullable column, the same shape as the existing
 * `max_users`/`max_companies` columns on this table -- reused that
 * convention rather than inventing a slug-keyed match() method (the
 * pattern `defaultWorkspaceKeys()`/`defaultModuleKeys()` use) precisely
 * so a Platform Admin can set/change this per-Plan from the existing
 * Plans admin UI, in data, without a code deploy -- matching this
 * phase's own "Package configuration should determine limits... do not
 * hardcode Starter/Professional/Enterprise into frontend [or, by the
 * same reasoning, backend] business logic" instruction.
 *
 * `null` = unlimited/custom (matches `max_users`/`max_companies`'s own
 * existing null-means-unlimited convention on this same table) --
 * Enterprise gets `null` here rather than a large hardcoded number, per
 * the product direction's own "Enterprise → configurable/custom".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('max_ptw_users')->nullable()->after('max_companies');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('max_ptw_users');
        });
    }
};
