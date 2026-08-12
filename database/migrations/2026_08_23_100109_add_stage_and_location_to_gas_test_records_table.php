<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v1.10.9 (HSE Domain Hardening). Gas Test was modeled as "belongs to
     * exactly one PermitToWork" only -- correct as far as it goes
     * (permit_to_work_id stays required, not nullable; a reading is still
     * always taken under a specific permit), but incomplete: a PTW can
     * cover a broad scope ("confined space entry") while gas readings are
     * actually taken at a specific sub-location/object within it (a tank,
     * a compartment, a specific line), and a permit legitimately needs
     * MULTIPLE readings over its duration (initial entry, periodic
     * re-test, final clearance) -- the schema already supported multiple
     * rows per permit (no unique constraint ever prevented it), it just
     * had no way to label WHERE or WHEN-IN-SEQUENCE each one was.
     *
     * `location`: plain nullable string, deliberately NOT a foreign key to
     * Asset or any other model -- see this migration's own audit notes in
     * docs/MODULES.md for why (a gas-test location is very often NOT a
     * registered Asset -- "Cargo Hold No. 2", "Engine Room" -- forcing an
     * Asset link would make valid non-asset locations impossible to
     * record). Mirrors `permits_to_work.location`'s own already-established
     * convention exactly, not a new pattern.
     *
     * `stage`: a real, validated, small string enum (see
     * GasTestRecord::STAGES) -- initial / re_test / final -- not a
     * boolean. Defaults to 'initial' so every pre-existing row (there is
     * no way to know retroactively what stage a historical reading
     * represented) reads as a sensible, harmless default rather than
     * null/undefined.
     */
    public function up(): void
    {
        // Guarded per-column (not just a bare Schema::table()) -- this is
        // an ALTER on an existing, already-shipped table, and a deploy
        // interrupted mid-migration must be safely retryable without an
        // "column already exists" failure (same reasoning as
        // Schema::createIfMissing() for brand-new tables, applied here to
        // an alter instead -- see docs/CONVENTIONS.md's Migrations
        // section).
        Schema::table('gas_test_records', function (Blueprint $table) {
            if (! Schema::hasColumn('gas_test_records', 'location')) {
                $table->string('location')->nullable()->after('permit_to_work_id');
            }
            if (! Schema::hasColumn('gas_test_records', 'stage')) {
                $table->string('stage')->default('initial')->after('tested_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('gas_test_records', function (Blueprint $table) {
            if (Schema::hasColumn('gas_test_records', 'location')) {
                $table->dropColumn('location');
            }
            if (Schema::hasColumn('gas_test_records', 'stage')) {
                $table->dropColumn('stage');
            }
        });
    }
};
