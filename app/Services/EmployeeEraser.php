<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2.115.0 -- REMOVING AN EMPLOYEE IS PERMANENT, AND THIS IS THE ONE
 * PLACE THAT KNOWS WHAT THAT TAKES WITH IT.
 *
 * Employee deletion used to be a SOFT delete. The reasoning was sound on
 * its own terms -- `employees` is the target of a long row of
 * `cascadeOnDelete` keys, so a hard delete destroys a person's whole
 * operational history -- but it produced a product that could not be
 * used: a row the customer had deliberately deleted still held its
 * employee number, so the corrected file could not be imported, and the
 * Employees page showed nobody while the import refused every row as a
 * duplicate.
 *
 * The owner's decision is that deletion is PERMANENT. This class is what
 * makes that safe to carry out, by answering for EVERY reference to the
 * row rather than letting the database decide by whatever `onDelete`
 * rule happened to be written months apart in twenty-four migrations.
 *
 * THREE KINDS OF REFERENCE, AND THEY ARE NOT THE SAME QUESTION.
 *
 *  1. DEPENDENTS -- records that exist only to describe this employee:
 *     their KPI entries, man-hours, PPE issues, competencies, rosters,
 *     shift assignments, internships, disciplinary cases, leave
 *     requests, project manpower rows, permit personnel rows, toolbox
 *     meeting attendance. Erasing the person erases these with them,
 *     because a KPI score belonging to nobody is not a record of
 *     anything.
 *
 *  2. REFERENCES -- records of their own that merely POINT at the
 *     employee: an incident report, a permit, an asset, a work order, a
 *     visitor log. The record survives and the pointer is cleared.
 *     Unassigned is already a legitimate state for every one of them.
 *
 *  3. NAMES ON DOCUMENTS -- the subset of (2) that PRINTS the person's
 *     name. A Permit to Work whose "Penanggung Jawab" line went blank
 *     because an unrelated HR action erased a row is a damaged safety
 *     document. So before the pointer is cleared, the name is written
 *     into the record as plain text -- a TOMBSTONE, not a duplicate: it
 *     is written at erasure time and only then, so there is never a
 *     second copy of a live name to drift out of sync (the mistake
 *     v2.101.0 is written up for).
 *
 * WHY THE APPLICATION DOES THIS AND NOT THE FOREIGN KEYS. Three of the
 * twenty-four keys are RESTRICT or NO ACTION, so a hard delete would
 * simply throw for anybody who had filed a daily report, attended a
 * toolbox meeting or requested leave. Changing delete rules in place is
 * also not portable -- SQLite cannot alter a constraint without
 * rebuilding the table, so the suite and production would disagree about
 * what deletion means, which is the one thing that must never be true of
 * a destructive operation. Doing it here means the behaviour is
 * identical on both engines, is counted, and can be asserted.
 *
 * WHAT IT NEVER TOUCHES: departments, positions, companies, projects,
 * shifts, roster patterns, competency types, PPE types -- the
 * organisation's own master data. Those are not the employee's records;
 * they existed before this person was hired and they outlive them. The
 * list below is the whole of what erasure reaches, and
 * `EmployeeErasureCoverageTest` reads the live schema and fails if any
 * new reference to `employees` is not named in it.
 */
class EmployeeEraser
{
    /**
     * Records that exist only to describe one employee: table => column.
     *
     * Deleted with the person. Every one of these is already
     * `cascadeOnDelete` in the schema except `leave_requests` and
     * `tbm_attendees`, which the database would refuse -- doing it here
     * means the two cases behave the same way and are counted the same
     * way.
     */
    public const DEPENDENTS = [
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

    /**
     * Records that survive with their pointer cleared.
     *
     * `name_column`, where present, is the text field that keeps the
     * person's name so the record still reads correctly afterwards.
     * `only_if_empty` is for a column the form already fills from the
     * employee (an incident report copies the injured person's name as
     * you pick them): we must not overwrite what a human typed, only
     * fill a gap the erasure would otherwise create.
     *
     * @var list<array{table: string, column: string, name_column?: string, only_if_empty?: bool}>
     */
    public const REFERENCES = [
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
        /*
         * v2.123.0 -- THE WORKER'S MY WORK LOGIN.
         *
         * A REFERENCE, not a dependent, and the distinction is the whole
         * point of ADR 055: `users` is a record of its own, pointed at by
         * activity logs, approvals and everything else a person did, and
         * deleting the row to tidy up a departure would take that with
         * it. The pointer is cleared and the account is DEACTIVATED in
         * the same pass (see eraseMany()), which is what actually ends
         * the access -- sign-in resolves a My Work account through the
         * register, so once the employee is gone there is no identifier
         * left that reaches it anyway.
         */
        ['table' => 'users', 'column' => 'employee_id'],
        ['table' => 'visitors', 'column' => 'host_employee_id'],
        ['table' => 'work_orders', 'column' => 'technician_id'],
    ];

    /**
     * What erasing these employees would remove, by table, without
     * removing anything.
     *
     * This is what the confirmation dialog states. A dialog that says
     * "their history is preserved" when it is not is worse than no
     * dialog at all, and that is exactly what the old copy said.
     *
     * @param  list<int>  $employeeIds
     * @return array{dependents: array<string, int>, references: array<string, int>, total_dependents: int, total_references: int}
     */
    public function impact(array $employeeIds): array
    {
        $dependents = [];
        $references = [];

        if ($employeeIds === []) {
            return ['dependents' => [], 'references' => [], 'total_dependents' => 0, 'total_references' => 0];
        }

        foreach (self::DEPENDENTS as $table => $column) {
            if (! $this->reachable($table, $column)) {
                continue;
            }

            $count = DB::table($table)->whereIn($column, $employeeIds)->count();

            if ($count > 0) {
                $dependents[$table] = $count;
            }
        }

        foreach (self::REFERENCES as $reference) {
            if (! $this->reachable($reference['table'], $reference['column'])) {
                continue;
            }

            $count = DB::table($reference['table'])->whereIn($reference['column'], $employeeIds)->count();

            if ($count > 0) {
                $key = $reference['table'].'.'.$reference['column'];
                $references[$key] = $count;
            }
        }

        return [
            'dependents' => $dependents,
            'references' => $references,
            'total_dependents' => array_sum($dependents),
            'total_references' => array_sum($references),
        ];
    }

    /**
     * Erase one employee permanently. Returns what went with them.
     *
     * @return array{dependents: array<string, int>, references: array<string, int>, names_kept: array<string, int>, total_dependents: int, total_references: int}
     */
    public function erase(Employee $employee): array
    {
        return $this->eraseMany(collect([$employee]));
    }

    /**
     * Erase a set of employees permanently, in ONE pass per table.
     *
     * Deliberately set-based rather than a loop of single erasures: a
     * bulk removal of four hundred people is otherwise twenty-four
     * statements four hundred times over, on shared hosting, inside one
     * transaction.
     *
     * The caller is responsible for authorization and for the tenant
     * boundary -- `BulkDestroyEmployeesRequest` resolves and checks the
     * set before this is reached, and the single-record path goes
     * through the policy and `assertInCurrentTenant()`. This class does
     * not re-derive that; it would be a second, divergent answer to a
     * question already settled.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array{dependents: array<string, int>, references: array<string, int>, names_kept: array<string, int>, total_dependents: int, total_references: int}
     */
    public function eraseMany(Collection $employees): array
    {
        $ids = $employees->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        if ($ids === []) {
            return ['dependents' => [], 'references' => [], 'names_kept' => [], 'total_dependents' => 0, 'total_references' => 0];
        }

        $names = $employees->mapWithKeys(fn (Employee $e) => [(int) $e->id => (string) $e->full_name])->all();

        return DB::transaction(function () use ($ids, $names) {
            $namesKept = $this->keepNamesOnDocuments($ids, $names);
            /*
             * Before the pointer is cleared, while these accounts can
             * still be found: a login issued to somebody who no longer
             * exists is not left active. Done here rather than by a
             * database rule so MySQL and SQLite agree, which is the same
             * reasoning the rest of this class is built on.
             */
            $this->deactivateAccountsFor($ids);
            $references = $this->clearReferences($ids);
            $dependents = $this->deleteDependents($ids);

            /*
             * The employees themselves, last, and through the query
             * builder rather than the model: `forceDelete()` on a model
             * that no longer has the SoftDeletes trait is just
             * `delete()`, and going through the builder keeps this one
             * statement for the whole set regardless of how many rows
             * the caller passed.
             */
            DB::table('employees')->whereIn('id', $ids)->delete();

            return [
                'dependents' => $dependents,
                'references' => $references,
                'names_kept' => $namesKept,
                'total_dependents' => array_sum($dependents),
                'total_references' => array_sum($references),
            ];
        });
    }

    /**
     * Write the person's name into the documents that print it, before
     * the link to them is cleared.
     *
     * @param  list<int>  $ids
     * @param  array<int, string>  $names
     * @return array<string, int>
     */
    private function keepNamesOnDocuments(array $ids, array $names): array
    {
        $kept = [];

        foreach (self::REFERENCES as $reference) {
            if (! isset($reference['name_column'])) {
                continue;
            }

            if (! $this->reachable($reference['table'], $reference['column'])
                || ! Schema::hasColumn($reference['table'], $reference['name_column'])) {
                continue;
            }

            foreach ($ids as $id) {
                $name = trim($names[$id] ?? '');

                if ($name === '') {
                    continue;
                }

                $query = DB::table($reference['table'])->where($reference['column'], $id);

                // Never overwrite a name a human typed; only fill the
                // gap this erasure would otherwise leave.
                if ($reference['only_if_empty'] ?? false) {
                    $query->where(function ($inner) use ($reference) {
                        $inner->whereNull($reference['name_column'])
                            ->orWhere($reference['name_column'], '');
                    });
                }

                $updated = $query->update([$reference['name_column'] => $name]);

                if ($updated > 0) {
                    $key = $reference['table'].'.'.$reference['name_column'];
                    $kept[$key] = ($kept[$key] ?? 0) + $updated;
                }
            }
        }

        return $kept;
    }

    /**
     * @param  list<int>  $ids
     */
    private function deactivateAccountsFor(array $ids): void
    {
        if (! $this->reachable('users', 'employee_id')) {
            return;
        }

        DB::table('users')->whereIn('employee_id', $ids)->update(['is_active' => false]);
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, int>
     */
    private function clearReferences(array $ids): array
    {
        $cleared = [];

        foreach (self::REFERENCES as $reference) {
            if (! $this->reachable($reference['table'], $reference['column'])) {
                continue;
            }

            $count = DB::table($reference['table'])
                ->whereIn($reference['column'], $ids)
                ->update([$reference['column'] => null]);

            if ($count > 0) {
                $cleared[$reference['table'].'.'.$reference['column']] = $count;
            }
        }

        return $cleared;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, int>
     */
    private function deleteDependents(array $ids): array
    {
        $deleted = [];

        foreach (self::DEPENDENTS as $table => $column) {
            if (! $this->reachable($table, $column)) {
                continue;
            }

            $count = DB::table($table)->whereIn($column, $ids)->delete();

            if ($count > 0) {
                $deleted[$table] = $count;
            }
        }

        return $deleted;
    }

    /**
     * Guarded on what is actually there, so an installation part-way
     * through its migrations erases what it can reach instead of failing
     * on a table a later release introduced.
     */
    private function reachable(string $table, string $column): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
