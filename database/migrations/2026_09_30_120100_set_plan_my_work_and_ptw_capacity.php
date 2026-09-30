<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v2.86.0 -- THE APPROVED CAPACITY FIGURES.
 *
 * Data only. The columns exist from the migration before this one; this
 * fills them with the approved model, mirroring the way
 * `establish_four_tier_pricing` set prices rather than leaving them to a
 * seeder that only runs on a fresh install.
 *
 * ENTERPRISE IS DELIBERATELY LEFT NULL ON BOTH COLUMNS.
 *
 * Null already means "no stated ceiling" everywhere else on `packages`
 * (`max_users`, `max_companies`), and Enterprise carries null on both today.
 * Its subscribers bought a plan with no stated limit, so giving it a number
 * here would be a silent downgrade of a live customer, which the approved
 * direction rules out explicitly. `PtwQuotaService` therefore treats a null
 * `ptw_included_monthly` as unmetered, and the entitlement layer treats a
 * null `max_my_work_users` as uncapped, exactly as they already treat
 * `max_users`.
 */
return new class extends Migration
{
    /** slug => [max_my_work_users, ptw_included_monthly] */
    private const CAPACITY = [
        'starter' => [10, 50],
        'professional' => [30, 200],
        'business' => [50, 500],
    ];

    public function up(): void
    {
        foreach (self::CAPACITY as $slug => [$myWork, $ptw]) {
            DB::table('packages')->where('slug', $slug)->update([
                'max_my_work_users' => $myWork,
                'ptw_included_monthly' => $ptw,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('packages')->whereIn('slug', array_keys(self::CAPACITY))->update([
            'max_my_work_users' => null,
            'ptw_included_monthly' => null,
        ]);
    }
};
