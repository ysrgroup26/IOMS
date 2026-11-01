<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.141.0 -- P4: AN APPROVAL NOBODY NEEDS ANY MORE IS CLOSED, NOT LEFT PENDING.
 *
 * On a parallel stage ("any one approver"), once one approver decided, the
 * other approvers' rows stayed `pending` for ever: the request kept showing
 * as waiting on them, and a second decision could advance or finalise an
 * already decided record a second time. Those rows are now marked
 * `skipped` the moment the stage is decided (approved, or rejected).
 *
 * NOT ADDITIVE: it widens an existing ENUM column. No row changes value and
 * nothing is dropped, but ADR 076's policy treats any column change as one
 * a person approves before production -- deliberately left that way.
 * Existing leftover `pending` siblings are NOT rewritten here; see the
 * roadmap for the read-only check to run before deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE approvals MODIFY status ENUM('pending','approved','rejected','skipped') NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('approvals', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'skipped'])->default('pending')->change();
        });
    }

    public function down(): void
    {
        // A `skipped` row has no place in the narrower column: it was never
        // decided, which is what `pending` used to (wrongly) say about it.
        DB::table('approvals')->where('status', 'skipped')->update(['status' => 'pending']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE approvals MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('approvals', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};
