<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.53.0 -- two additions that both narrow access rather than widen it.
 *
 * 1. `company_user` -- PER-COMPANY AUTHORIZATION INSIDE A TENANT.
 *
 *    Enterprise sells multi-company: one customer running GAJ and MTC in
 *    the same workspace. Until now a tenant user could reach EVERY company
 *    in their tenant, because TenantScope narrows to the tenant and
 *    nothing narrowed further. That is fine for a single-company customer
 *    and wrong for a multi-company one, where a yard manager at GAJ has no
 *    business in MTC's payroll or purchase orders.
 *
 *    THE DEFAULT IS DELIBERATELY "NO ROWS = ALL COMPANIES IN THE TENANT".
 *    Every existing user has no rows, so behaviour is byte-for-byte
 *    unchanged on upgrade and nobody is locked out of their own data by a
 *    migration. Authorization becomes real the moment an administrator
 *    grants a user their first company — from then on that user sees only
 *    what they were granted. Opt-in, and it only ever removes access.
 *
 * 2. `tenants.is_demo` -- marks the Sandbox tenant.
 *
 *    The Sandbox is a real tenant with real isolation, not a special code
 *    path, so it inherits every boundary the product already enforces.
 *    The flag exists so the application can refuse writes and refuse to
 *    treat demo data as a customer's — never to grant it anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // One grant per pair. The unique index is the enforcement, not
            // an application check that could be raced.
            $table->unique(['user_id', 'company_id']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'is_demo')) {
                $table->boolean('is_demo')->default(false)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'is_demo')) {
                $table->dropColumn('is_demo');
            }
        });
    }
};
