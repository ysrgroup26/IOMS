<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * v2.115.0 -- EMPLOYEE DELETION BECOMES PERMANENT, AND THE ROWS ALREADY
 * DELETED ARE FINALLY GONE.
 *
 * `Employee` carried `SoftDeletes`. Nothing in the product ever showed a
 * deleted employee, offered to restore one, or filtered for them -- so
 * the only observable effect of the trait was that a row the customer
 * had deliberately removed still held its employee number and NIK
 * against the unique indexes, which know nothing about `deleted_at`.
 * Reported from production in the worst possible shape: an import of 75
 * employees refused EVERY row as a duplicate while the Employees page
 * said there were no employees at all.
 *
 * The owner's decision is that deletion is permanent. `EmployeeEraser`
 * is how a deletion now answers for each of the twenty-four references
 * to the row; this migration deals with the rows that were deleted
 * BEFORE it existed, because leaving them is not an option either way:
 *
 *   - keep `deleted_at` and they go on blocking imports, which is the
 *     defect;
 *   - drop `deleted_at` and leave the rows, and every employee the
 *     customer has ever deleted REAPPEARS in their active list, which is
 *     worse.
 *
 * SO THIS MIGRATION DELETES DATA, AND THAT IS THE POINT. It removes
 * exactly the employees whose `deleted_at` is set -- rows an
 * administrator already chose to remove -- and the dependent records
 * that describe only them. It cannot be undone: `down()` restores the
 * COLUMN so the schema can go back, and says plainly that the rows
 * cannot. On an installation that never soft-deleted anybody it changes
 * no data at all.
 *
 * THE LISTS BELOW ARE FROZEN COPIES of `EmployeeEraser`'s, and
 * deliberately not a call into it. A migration has to do the same thing
 * in two years as it does today, against whatever that class has become;
 * a data migration that follows live application code is a migration
 * whose behaviour nobody can state. `EmployeeErasureCoverageTest` keeps
 * the LIVE list honest against the schema; this one is a record of what
 * was true when it ran.
 *
 * Portable: query-builder statements only, guarded on what exists, no
 * `UPDATE ... JOIN` and no `information_schema`.
 */
return new class extends Migration
{
    /** Records that exist only to describe one employee: table => column. */
    private const DEPENDENTS = [
        'employee_cases' => 'employee_id',
        'employee_competencies' => 'employee_id',
        'employee_internships' => 'employee_id',
        'employee_ppe' => 'employee_id',
        'employee_rosters' => 'employee_id',
        'employee_shift_assignments' => 'employee_id',
        'kpi_records' => 'employee_id',
        'leave_requests' => 'employee_id',
        'man_hour_logs' => 'employee_id',
        'permit_to_work_personnel' => 'employee_id',
        'project_manpower' => 'employee_id',
        'tbm_attendees' => 'employee_id',
    ];

    /** Records that survive with their pointer cleared. */
    private const REFERENCES = [
        ['table' => 'assets', 'column' => 'responsible_employee_id'],
        ['table' => 'asset_transactions', 'column' => 'from_employee_id'],
        ['table' => 'asset_transactions', 'column' => 'to_employee_id'],
        ['table' => 'daily_reports', 'column' => 'hse_officer_id', 'name_column' => 'hse_officer_name'],
        ['table' => 'handover_records', 'column' => 'first_party_employee_id'],
        ['table' => 'incidents', 'column' => 'injured_employee_id', 'name_column' => 'injured_person_name', 'only_if_empty' => true],
        ['table' => 'investigation_interviews', 'column' => 'employee_id'],
        ['table' => 'permits_to_work', 'column' => 'pic_employee_id', 'name_column' => 'pic_name'],
        ['table' => 'project_activities', 'column' => 'assigned_employee_id'],
        ['table' => 'regulation_registers', 'column' => 'owner_employee_id'],
        ['table' => 'visitors', 'column' => 'host_employee_id'],
        ['table' => 'work_orders', 'column' => 'technician_id'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        if (Schema::hasColumn('employees', 'deleted_at')) {
            $this->purgeAlreadyDeletedEmployees();

            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('deleted_at');
            });
        }
    }

    /**
     * Restores the column, and nothing else.
     *
     * The rows this migration removed are gone; a rollback cannot invent
     * them, and pretending otherwise would be worse than saying so. What
     * `down()` buys is a schema that matches the previous release, so a
     * rollback for an unrelated reason is not blocked by this one.
     */
    public function down(): void
    {
        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'deleted_at')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    private function purgeAlreadyDeletedEmployees(): void
    {
        $ids = DB::table('employees')->whereNotNull('deleted_at')->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        $removed = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            // The name first, so a document that printed it still reads
            // correctly once the link is cleared.
            foreach (self::REFERENCES as $reference) {
                if (! isset($reference['name_column'])
                    || ! $this->reachable($reference['table'], $reference['column'])
                    || ! Schema::hasColumn($reference['table'], $reference['name_column'])) {
                    continue;
                }

                foreach ($chunk as $id) {
                    $name = trim((string) DB::table('employees')->where('id', $id)->value('full_name'));

                    if ($name === '') {
                        continue;
                    }

                    $query = DB::table($reference['table'])->where($reference['column'], $id);

                    if ($reference['only_if_empty'] ?? false) {
                        $query->where(function ($inner) use ($reference) {
                            $inner->whereNull($reference['name_column'])
                                ->orWhere($reference['name_column'], '');
                        });
                    }

                    $query->update([$reference['name_column'] => $name]);
                }
            }

            foreach (self::REFERENCES as $reference) {
                if (! $this->reachable($reference['table'], $reference['column'])) {
                    continue;
                }

                DB::table($reference['table'])
                    ->whereIn($reference['column'], $chunk)
                    ->update([$reference['column'] => null]);
            }

            foreach (self::DEPENDENTS as $table => $column) {
                if (! $this->reachable($table, $column)) {
                    continue;
                }

                $count = DB::table($table)->whereIn($column, $chunk)->delete();

                if ($count > 0) {
                    $removed[$table] = ($removed[$table] ?? 0) + $count;
                }
            }

            DB::table('employees')->whereIn('id', $chunk)->delete();
        }

        $summary = sprintf(
            'v2.115.0 migration: purged %d employee(s) that were already soft-deleted, '
            .'and %d dependent record(s) belonging only to them (%s). '
            .'Departments, positions, companies and every other master record were not touched.',
            count($ids),
            array_sum($removed),
            $removed === [] ? 'none' : json_encode($removed)
        );

        Log::info($summary);

        // Visible in the deploy output too, because a deploy that
        // deletes rows should say how many on the terminal the operator
        // is watching, not only in a log they may never open.
        if (app()->runningInConsole()) {
            fwrite(STDERR, PHP_EOL.$summary.PHP_EOL.PHP_EOL);
        }
    }

    private function reachable(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
};
