<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v2.53.0 -- PTW Access stops being a SOLD CAPACITY.
 *
 * It was a second seat pool alongside `max_users`, which made every plan
 * card read as two numbers a buyer then had to reconcile ("10 users and 5
 * PTW Access -- is that fifteen?"). Capacity is now expressed as TOTAL
 * USERS, one number, and PTW Access goes back to being what it always
 * was operationally: an internal permission an administrator grants to an
 * account that already exists.
 *
 * WHAT THIS DOES NOT DO, and the distinction matters:
 *
 *   - `users.ptw_access` stays. So does `User::canCreatePtw()`, and so
 *     does PermitToWorkController's server-side gate. Nobody gains the
 *     ability to raise a permit who did not have it before.
 *   - Only the QUOTA is retired. `max_ptw_users` is nulled, which the
 *     entitlement layer already reads as "no ceiling", so granting PTW
 *     Access simply stops being counted against a purchased allowance.
 *
 * Also fixes the company structure: Professional is a ONE-company plan.
 * It had been advertising five, which was neither the commercial decision
 * nor what the tier is for -- multi-company is what Enterprise sells.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Null = no ceiling to EntitlementService. Retiring the quota by
        // clearing the column keeps the schema intact for a future
        // decision without leaving a half-enforced limit behind.
        DB::table('packages')->update(['max_ptw_users' => null, 'updated_at' => now()]);

        DB::table('packages')->whereIn('slug', ['starter', 'professional'])
            ->update(['max_companies' => 1, 'updated_at' => now()]);

        DB::table('packages')->where('slug', 'enterprise')
            ->update(['max_companies' => null, 'updated_at' => now()]);
    }

    /**
     * Not reversible. Restoring a PTW seat ceiling would re-impose a quota
     * on tenants who have since granted PTW Access freely, and could leave
     * a tenant over a limit they were never told about. Capacity is
     * business data -- set it from Platform Admin > Plans.
     */
    public function down(): void {}
};
