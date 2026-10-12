<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.114.0 -- "WE DO NOT KNOW" HAD TO BE REPRESENTABLE.
 *
 * `employment_type` was NOT NULL with a `pkwtt` default, so a roster
 * whose file carries no Workforce Type column at all imported every
 * single person as PERMANENT. That is not a missing value quietly
 * defaulted; it is the system asserting a contract type on somebody's
 * behalf, in a field that matters for payroll, BPJS registration and
 * government reporting. A customer who imported 75 people found all 75
 * labelled with a contract their file never mentioned.
 *
 * THE DEFAULT IS DELIBERATELY KEPT. Every other path that creates an
 * employee either supplies the value (both form requests mark it
 * `required`) or relies on the default (`EmployeeFactory`), and changing
 * what those paths do is not what this migration is for. Only an
 * EXPLICIT null -- which is written by the importer, and only when the
 * source genuinely did not say -- now means "unknown".
 *
 * Existing rows are untouched: a column becoming nullable never rewrites
 * data, so every employee keeps the type they already have.
 *
 * Portable: one `change()` on one column, which Laravel expresses natively
 * on MySQL and on the SQLite the suite runs against. No raw
 * `MODIFY COLUMN`, no `information_schema` read (see CONVENTIONS.md on
 * driver-aware migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'employment_type')) {
            return;
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->string('employment_type')->nullable()->default('pkwtt')->change();
        });
    }

    /**
     * Reversing this would have to decide what an unknown type IS, and
     * there is no honest answer -- so it writes the column's own default
     * into the rows that are null, which is exactly the state they were
     * in before, and then restores NOT NULL.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('employees', 'employment_type')) {
            return;
        }

        DB::table('employees')
            ->whereNull('employment_type')
            ->update(['employment_type' => 'pkwtt']);

        Schema::table('employees', function (Blueprint $table) {
            $table->string('employment_type')->default('pkwtt')->change();
        });
    }
};
