<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.127.0 -- TWO DECISIONS, TWO RECORDS.
 *
 * `hse_approver_id` held the single approval. With verification and
 * approval now separate acts performed by different people, one column
 * cannot hold both: overloading it would make the APPROVED stamp name
 * whoever acted last, which is precisely the kind of quiet
 * misrepresentation the stamp family exists to prevent (ADR 065).
 *
 * So verification gets its own actor column and its own timestamp, and
 * approval gets the timestamp it never had.
 *
 * NOTHING IS BACKFILLED. A permit approved before this release genuinely
 * has no verification: `hse_verifier_id` stays null and the VERIFIED stamp
 * does not render for it, which is honest. `hse_approver_id` keeps its
 * meaning exactly -- it is still the final approval -- so every existing
 * approved permit still stamps as approved, and `approved_at` is null for
 * them because nobody recorded one. The read path falls back to the
 * ActivityLog row for the time, the same way it already does for the name
 * and the job title.
 *
 * `status` is a varchar, not an enum, so the new `verified` state needs no
 * schema change -- which is the reason this codebase uses varchar for
 * status columns (see docs/CONVENTIONS.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permits_to_work', function (Blueprint $table) {
            // Nullable on delete: a permit outlives the account that
            // signed it, and the stamp reads its identity from the
            // ActivityLog row anyway (v2.100.0).
            $table->foreignId('hse_verifier_id')->nullable()->after('area_authority_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('hse_verifier_id');
            $table->timestamp('approved_at')->nullable()->after('hse_approver_id');
        });
    }

    public function down(): void
    {
        Schema::table('permits_to_work', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hse_verifier_id');
            $table->dropColumn(['verified_at', 'approved_at']);
        });
    }
};
