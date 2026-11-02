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
 * Existing leftover `pending` siblings are NOT rewritten (history is kept);
 * the engine refuses to decide them and lists them as waiting on nobody.
 * Runbook: docs/runbooks/P4-SKIPPED-STATUS.md.
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
        /*
         * History is never rewritten (the owner's rule): a `skipped` row is a
         * record that a stage was decided without it. Narrowing the column
         * with such rows present would have to change them, so the rollback
         * REFUSES and says why. The recovery path is to roll back the
         * APPLICATION only and keep this wider column -- code from before
         * v2.141.0 reads only pending/approved/rejected and ignores `skipped`
         * rows (see docs/runbooks/P4-SKIPPED-STATUS.md).
         */
        $skipped = DB::table('approvals')->where('status', 'skipped')->count();
        if ($skipped > 0) {
            throw new RuntimeException("{$skipped} approval rows are `skipped`; narrowing the status column would rewrite approval history. Roll back the application only and keep this column (docs/runbooks/P4-SKIPPED-STATUS.md).");
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE approvals MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('approvals', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};
