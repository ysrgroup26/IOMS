<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.115.0 -- TWO DOCUMENTS PRINTED A NAME THEY DID NOT OWN.
 *
 * Employee deletion becomes PERMANENT in this release (see
 * `EmployeeEraser` and ADR 055). Every record that merely points at an
 * employee keeps itself and loses the pointer, which is correct for an
 * asset, a work order or a visitor log -- unassigned is a real state for
 * all of them.
 *
 * It is NOT correct for a document that prints the person's name. A
 * Permit to Work renders "Penanggung Jawab" from `pic_employee_id`, and
 * a pre-v1.5.1 Daily Report is attributed through `hse_officer_id`.
 * Clearing those would leave a signed safety document with a blank
 * accountable party, as a side effect of an unrelated HR action.
 *
 * Every other document already carries the name as text --
 * `incidents.injured_person_name`, `investigation_interviews.person_name`,
 * `handover_records.first_party_name` -- which is why only these two need
 * a column.
 *
 * THESE ARE TOMBSTONES, NOT DUPLICATES. Nothing writes them on create or
 * update; `EmployeeEraser` writes them at the moment it clears the link,
 * and only then. So there is never a second copy of a LIVE name that can
 * drift from the employee record -- the mistake `CONVENTIONS.md` records
 * for v2.101.0. The read paths ask the relation first and fall back to
 * the stored text, so a permit whose PIC still exists is unaffected.
 *
 * The backfill is deliberate: it captures the name as it is TODAY for
 * every document that currently names a live employee, so a document
 * printed after a future erasure reads the same as one printed before
 * it. Narrow (two nullable string columns, no constraint, no index), so
 * it cannot fail on a populated production table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permits_to_work') && ! Schema::hasColumn('permits_to_work', 'pic_name')) {
            Schema::table('permits_to_work', function (Blueprint $table) {
                $table->string('pic_name')->nullable()->after('pic_employee_id');
            });

            $this->backfill('permits_to_work', 'pic_employee_id', 'pic_name');
        }

        if (Schema::hasTable('daily_reports') && ! Schema::hasColumn('daily_reports', 'hse_officer_name')) {
            Schema::table('daily_reports', function (Blueprint $table) {
                $table->string('hse_officer_name')->nullable()->after('hse_officer_id');
            });

            $this->backfill('daily_reports', 'hse_officer_id', 'hse_officer_name');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permits_to_work') && Schema::hasColumn('permits_to_work', 'pic_name')) {
            Schema::table('permits_to_work', function (Blueprint $table) {
                $table->dropColumn('pic_name');
            });
        }

        if (Schema::hasTable('daily_reports') && Schema::hasColumn('daily_reports', 'hse_officer_name')) {
            Schema::table('daily_reports', function (Blueprint $table) {
                $table->dropColumn('hse_officer_name');
            });
        }
    }

    /**
     * Copied row by row through the query builder rather than with an
     * `UPDATE ... JOIN`, which MySQL supports and SQLite does not -- the
     * portability rule in `CONVENTIONS.md`. Chunked, because this runs
     * against a populated table.
     */
    private function backfill(string $table, string $idColumn, string $nameColumn): void
    {
        if (! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'full_name')) {
            return;
        }

        DB::table('employees')
            ->select('id', 'full_name')
            ->orderBy('id')
            ->chunk(500, function ($employees) use ($table, $idColumn, $nameColumn) {
                foreach ($employees as $employee) {
                    if (trim((string) $employee->full_name) === '') {
                        continue;
                    }

                    DB::table($table)
                        ->where($idColumn, $employee->id)
                        ->whereNull($nameColumn)
                        ->update([$nameColumn => $employee->full_name]);
                }
            });
    }
};
