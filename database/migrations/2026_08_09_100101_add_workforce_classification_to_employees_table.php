<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream A (Workforce Classification). IOMS's
     * `employees` table previously had no concept of workforce
     * classification -- every row was implicitly treated the same,
     * whether a permanent (PKWTT) staff member, a fixed-term (PKWT)
     * contract worker, a daily worker, an intern/PKL, or an external
     * contractor/outsourced worker. Indonesian industrial employers are
     * legally and operationally required to distinguish these.
     *
     * `employment_type` is a plain string (not a DB enum) so the set of
     * classifications can be extended later without a schema migration
     * -- matches this codebase's existing convention of avoiding DB
     * enums for anything that might grow (see `status` on `tenants`,
     * which is a plain string with app-level constants, not a DB enum).
     * Constants live on `Employee` (`EMPLOYMENT_TYPE_*`).
     *
     * Defaults to `pkwtt` (permanent) for every existing row -- the
     * conservative choice: every employee already in this system before
     * this migration was created and managed as an ordinary permanent
     * staff member, so treating them as PKWTT preserves that behavior
     * exactly rather than leaving them unclassified or guessing wrong.
     *
     * `contract_start_date`/`contract_end_date` are relevant for PKWT,
     * daily, intern, PKL, contractor, and outsourced workers alike (a
     * PKWTT/permanent employee has no contract end date, hence
     * nullable) -- kept on `employees` itself rather than only inside
     * the new `employee_internships` table (2026_08_09_100102) because
     * every workforce type except PKWTT can have a contract period, not
     * just interns/PKL.
     *
     * `nik` (national ID number) was deliberately DROPPED from this
     * table in 2026_07_16_100003 ("NIK data confirmed acceptable to
     * remove" -- Employee ID already served as the unique employee
     * number at the time). Re-added here, nullable, because Indonesian
     * industrial HR record-keeping genuinely needs it (BPJS
     * registration, government reporting) and the current spec requires
     * it -- this does NOT restore any of the old dropped values (that
     * data is gone, per that migration's own down() comment), it only
     * makes the column available again for new/updated records.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employment_type')->default('pkwtt')->after('status');
            $table->date('contract_start_date')->nullable()->after('join_date');
            $table->date('contract_end_date')->nullable()->after('contract_start_date');
            $table->string('nik', 20)->nullable()->after('employee_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->index('employment_type');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['employment_type']);
            $table->dropColumn(['employment_type', 'contract_start_date', 'contract_end_date', 'nik']);
        });
    }
};
