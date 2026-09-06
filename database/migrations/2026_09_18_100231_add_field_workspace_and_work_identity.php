<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.52.0 -- two independent semantic corrections that both needed a
 * column.
 *
 * 1. `users.is_field_user` -- MY WORK IS A WORKSPACE, NOT A PERMISSION.
 *
 *    Field routing was previously inferred from `department_key`
 *    (DashboardController's own comment called that a "deliberate,
 *    documented MVP proxy" and predicted it would be too coarse). It is:
 *    it catches an office-based single-department user who never sets
 *    foot on site, and misses a foreman who happens to be
 *    multi-department.
 *
 *    This flag says the thing directly -- "this account performs,
 *    supervises or participates in field work" -- so a foreman,
 *    supervisor, technician or operator can be sent straight to My Work
 *    at login instead of an office dashboard that is irrelevant to them.
 *
 *    IT IS NOT PTW ACCESS, and the two must never be conflated:
 *      is_field_user -> which WORKSPACE you land in
 *      ptw_access    -> whether you may CREATE a Permit To Work
 *    A field worker normally has the first and not the second; a foreman
 *    may have both. Nothing about this column changes what any user is
 *    authorized to do -- PermitToWorkController's server-side gate is
 *    untouched.
 *
 * 2. `permits_to_work.project_name` -- PROJECT IDENTITY AND WORK LOCATION
 *    ARE DIFFERENT THINGS.
 *
 *    PTW already had `project_id` (a formal Project Master reference) and
 *    `location`. The gap was that a permit whose work has a real name --
 *    "Docking MV Sinar Mas", "Overhaul Crane #4" -- had nowhere to record
 *    it unless Management had first created a Project Master row, so the
 *    printed permit and every list read "No Project" for work that
 *    plainly has an identity. HSE cannot be blocked on Management's
 *    backlog.
 *
 *    `project_name` is the free-text work identity, used when no formal
 *    project applies. When `project_id` IS set, the Project Master
 *    remains authoritative and this stays empty -- one field never
 *    silently overrides the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_field_user')) {
                $table->boolean('is_field_user')->default(false)->after('ptw_access');
            }
        });

        Schema::table('permits_to_work', function (Blueprint $table) {
            if (! Schema::hasColumn('permits_to_work', 'project_name')) {
                $table->string('project_name')->nullable()->after('project_id');
            }
        });

        // Backfill: every account that ALREADY held PTW Access is, by
        // definition, someone who raises permits for work in the field, so
        // routing them to My Work matches what they already do. This
        // changes no permission -- only which page they land on -- and an
        // administrator can clear it per account in Settings > Users.
        if (Schema::hasColumn('users', 'ptw_access')) {
            DB::table('users')->where('ptw_access', true)->update(['is_field_user' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_field_user')) {
                $table->dropColumn('is_field_user');
            }
        });

        Schema::table('permits_to_work', function (Blueprint $table) {
            if (Schema::hasColumn('permits_to_work', 'project_name')) {
                $table->dropColumn('project_name');
            }
        });
    }
};
