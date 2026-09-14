<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.69.0 -- THE STATE A REQUEST WAS ALWAYS IN BUT COULD NEVER SAY.
 *
 * A Material Request that has been approved sits in `approved` or
 * `processing` until somebody completes it, and in practice that is
 * months. Three genuinely different situations were collapsed into those
 * two values:
 *
 *   1. Approved, nobody has picked it up yet.
 *   2. Approved, and Procurement has DELIBERATELY retained it so it can
 *      be bought together with related demand. This is correct, normal
 *      procurement behaviour -- buying one box of gloves alone is
 *      wasteful -- and it is the single most common reason a request
 *      legitimately sits still.
 *   3. Actively being fulfilled.
 *
 * Only (3) was expressible. (2) was indistinguishable from a forgotten
 * request, which is exactly why forgotten requests were invisible: when
 * the healthy case and the failure case look identical, neither can be
 * managed.
 *
 * `consolidating` names (2). It is deliberately NOT called "on hold" --
 * `on_hold` already exists in this codebase's shared status vocabulary
 * (StatusBadge) meaning "stopped, waiting on something", which is the
 * opposite claim: consolidation is a decision to WAIT ON PURPOSE, and it
 * carries a reason, an owner and a timestamp so it can be reviewed.
 *
 * WHY NO `closed`. A separate `closed` terminal state alongside
 * `cancelled` was considered and rejected: both mean "ended without
 * fulfilment", and the real gap was never a missing state -- it was that
 * `cancelled` recorded no REASON. A second terminal state whose only
 * distinction is the story behind it is a duplicate concept with a
 * different name, which is what `cancellation_reason` fixes properly.
 * Reasoning recorded in docs/ADR/030-material-request-lifecycle-and-consolidation.md.
 */
return new class extends Migration
{
    private const STATUSES_AFTER = "'draft', 'submitted', 'approved', 'consolidating', 'rejected', 'processing', 'completed', 'cancelled'";

    private const STATUSES_BEFORE = "'draft', 'submitted', 'approved', 'rejected', 'processing', 'completed', 'cancelled'";

    public function up(): void
    {
        // Same guard, and for the same reason, as
        // 2026_08_10_100033_extend_material_requests_status_enum_v2:
        // `MODIFY COLUMN` is MySQL-only DDL and would stop any other
        // driver (notably the test suite's SQLite) from provisioning the
        // schema at all. SQLite columns are dynamically typed, so
        // widening is a no-op there.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE material_requests MODIFY COLUMN status ENUM('.self::STATUSES_AFTER.") NOT NULL DEFAULT 'draft'");
        }

        Schema::table('material_requests', function (Blueprint $table) {
            // Consolidation is a decision, so it records who made it and
            // why. Without the reason this is just another status nobody
            // can explain three weeks later.
            $table->text('consolidation_reason')->nullable()->after('notes');
            $table->foreignId('consolidated_by')->nullable()->after('consolidation_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('consolidated_at')->nullable()->after('consolidated_by');

            // The gap that made a separate `closed` state look necessary:
            // a cancelled request could never say why it ended.
            $table->text('cancellation_reason')->nullable()->after('consolidated_at');
        });

        // Aging is read as "every request not in a terminal state, oldest
        // first". That is a status filter plus a date sort on every
        // outstanding-work query, on the one table this release makes
        // people look at far more often.
        Schema::table('material_requests', function (Blueprint $table) {
            $table->index(['status', 'request_date'], 'material_requests_status_request_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('material_requests', function (Blueprint $table) {
            $table->dropIndex('material_requests_status_request_date_index');
            $table->dropConstrainedForeignId('consolidated_by');
            $table->dropColumn(['consolidation_reason', 'consolidated_at', 'cancellation_reason']);
        });

        // Anything still parked in `consolidating` has to land somewhere
        // the narrowed enum can represent. `approved` is where it came
        // from and is the only non-destructive answer -- the request is
        // still live and still owed to its requester.
        DB::table('material_requests')->where('status', 'consolidating')->update(['status' => 'approved']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE material_requests MODIFY COLUMN status ENUM('.self::STATUSES_BEFORE.") NOT NULL DEFAULT 'draft'");
        }
    }
};
