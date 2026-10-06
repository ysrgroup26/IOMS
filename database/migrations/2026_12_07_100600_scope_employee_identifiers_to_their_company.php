<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.105.0 -- AN EMPLOYEE NUMBER BELONGS TO A COMPANY, NOT TO THE PLANET.
 *
 * `employees.employee_id` has carried a GLOBAL unique index since
 * 2024_01_01_000005, written when IOMS was one company's internal tool. It
 * was never revisited when multi-company arrived, and never when multi-
 * tenancy did. The consequence is as bad as it sounds:
 *
 *   ONE CUSTOMER'S "EMP-001" PREVENTED EVERY OTHER CUSTOMER FROM EVER
 *   HAVING AN "EMP-001".
 *
 * Employee numbers are a company's own numbering scheme. EMP-001, 1001 and
 * 00123 are exactly the sequences every yard starts with, so collisions
 * between unrelated customers were not a remote possibility -- they were
 * the expected case, and the second customer to try simply could not
 * import their workforce.
 *
 * HOW IT PRESENTED, and why it took this long to find: the import preview
 * checks for duplicates WITHIN THE FILE and never against the database, so
 * a 141-row file reported 141 valid rows. The insert then hit the unique
 * index, threw, and rolled the whole transaction back -- including the
 * departments and positions created moments earlier. The customer saw
 * "Import failed", with 141 valid rows on the screen behind it.
 *
 * `nik` had the identical problem and is fixed in the same breath. A NIK
 * identifies a person nationally, so two unrelated customers employing the
 * same person is ordinary, not a data error.
 *
 * NARROWING UNIQUENESS CAN NEVER FAIL ON EXISTING DATA. Every set that is
 * unique globally is also unique within each company, so this applies to a
 * populated production table without any possibility of a constraint
 * violation. It only ever permits rows that were previously refused.
 *
 * Portable: index operations only, no column changes, so it applies the
 * same way on MySQL and on the SQLite the suite runs against.
 */
return new class extends Migration
{
    /**
     * Every step is guarded on what is actually there.
     *
     * An index change is one of the few migrations that can half-apply: the
     * drops succeed, an add fails, and the table is left with NO uniqueness
     * at all -- which is strictly worse than either shape. Re-running then
     * fails on a drop that has already happened, so the deploy is stuck
     * with the weakest possible constraint in place.
     *
     * Guarded, this can be re-run against a table in any of those states
     * and finish correctly, which is what a deploy actually needs.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasIndex('employees', 'employees_employee_id_unique')) {
                $table->dropUnique('employees_employee_id_unique');
            }

            if (Schema::hasIndex('employees', 'employees_nik_unique')) {
                $table->dropUnique('employees_nik_unique');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            // The company owns the numbering, so the company is part of the
            // key. A null `nik` stays unconstrained on both engines, which
            // is correct: not recording one is not a clash with every other
            // employee who has none.
            if (! Schema::hasIndex('employees', 'employees_company_employee_id_unique')) {
                $table->unique(['company_id', 'employee_id'], 'employees_company_employee_id_unique');
            }

            if (! Schema::hasIndex('employees', 'employees_company_nik_unique')) {
                $table->unique(['company_id', 'nik'], 'employees_company_nik_unique');
            }
        });
    }

    public function down(): void
    {
        /*
         * THE GLOBAL UNIQUES ARE RESTORED FIRST, AND THAT ORDER IS THE
         * WHOLE POINT.
         *
         * Restoring them can legitimately FAIL, because rows this migration
         * made possible may now exist -- two companies each with their own
         * EMP-001. That is not a fault in the rollback; it is the data
         * saying the old constraint was wrong.
         *
         * Dropping the per-company uniques first and then failing here
         * would leave the table with NO uniqueness on an employee number at
         * all, which is worse than either shape and is exactly what an
         * earlier version of this method did. Adding first means a failed
         * rollback leaves the stronger, correct constraint in place and
         * changes nothing.
         */
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasIndex('employees', 'employees_employee_id_unique')) {
                $table->unique('employee_id', 'employees_employee_id_unique');
            }

            if (! Schema::hasIndex('employees', 'employees_nik_unique')) {
                $table->unique('nik', 'employees_nik_unique');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasIndex('employees', 'employees_company_employee_id_unique')) {
                $table->dropUnique('employees_company_employee_id_unique');
            }

            if (Schema::hasIndex('employees', 'employees_company_nik_unique')) {
                $table->dropUnique('employees_company_nik_unique');
            }
        });
    }
};
