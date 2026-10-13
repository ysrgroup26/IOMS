<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Services\EmployeeEraser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * EVERY REFERENCE TO AN EMPLOYEE HAS AN ANSWER, AND THE SCHEMA DECIDES
 * WHAT "EVERY" MEANS.
 *
 * `EmployeeEraser` carries two hand-written lists: the records that are
 * deleted with an employee, and the records whose pointer is cleared. A
 * hand-written list of twenty-four foreign keys is exactly the kind of
 * thing that is correct on the day it is written and silently wrong three
 * releases later, when somebody adds a twenty-fifth.
 *
 * What makes it wrong is not obvious at the call site either. A reference
 * the eraser does not know about is one of two failures:
 *
 *  - the key is RESTRICT or NO ACTION, and every permanent deletion of
 *    anybody who appears in that table starts throwing;
 *  - the key is SET NULL or CASCADE, and the database quietly does
 *    something nobody decided -- including deleting a document that
 *    should have survived.
 *
 * So this test does not read the lists. It reads the LIVE SCHEMA, finds
 * every column that references `employees`, and fails naming any that the
 * eraser has no policy for. Adding a reference now means choosing what
 * happens to it, which is the whole point.
 */
class EmployeeErasureCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{table: string, column: string}> */
    private function referencesToEmployees(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $rows = DB::select(
                'select k.table_name as `table`, k.column_name as `column`
                 from information_schema.key_column_usage k
                 where k.table_schema = database()
                   and k.referenced_table_name = ?',
                ['employees']
            );

            return array_map(fn ($row) => ['table' => $row->table, 'column' => $row->column], $rows);
        }

        /*
         * SQLite has no information_schema, so the keys are read table by
         * table through `PRAGMA foreign_key_list`. Slower, and the only
         * way to ask the question on the engine the suite runs on -- and
         * asking it on BOTH engines is the point, because a migration
         * that applies differently on the two is its own known pitfall.
         */
        $references = [];

        $tables = array_map(
            fn ($row) => $row->name,
            DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'")
        );

        foreach ($tables as $table) {
            foreach (DB::select('PRAGMA foreign_key_list('.$table.')') as $key) {
                if ($key->table === 'employees') {
                    $references[] = ['table' => $table, 'column' => $key->from];
                }
            }
        }

        return $references;
    }

    public function test_the_eraser_has_a_policy_for_every_reference_to_an_employee(): void
    {
        $references = $this->referencesToEmployees();

        $this->assertNotEmpty($references, 'No foreign keys to employees were found -- the probe itself is broken.');

        $known = [];

        foreach (EmployeeEraser::DEPENDENTS as $table => $column) {
            $known[] = $table.'.'.$column;
        }

        foreach (EmployeeEraser::REFERENCES as $reference) {
            $known[] = $reference['table'].'.'.$reference['column'];
        }

        $unanswered = [];

        foreach ($references as $reference) {
            $key = $reference['table'].'.'.$reference['column'];

            if (! in_array($key, $known, true)) {
                $unanswered[] = $key;
            }
        }

        $this->assertSame([], $unanswered, implode("\n", [
            'These columns reference employees and EmployeeEraser has no policy for them: '
            .implode(', ', $unanswered).'.',
            'Add each to DEPENDENTS (a record that only describes the employee, deleted with them)',
            'or to REFERENCES (a record of its own, keeping itself and losing the pointer) --',
            'and if the record PRINTS the name, give it a name_column so the document still reads.',
            'See docs/ADR/054 and docs/ADR/055.',
        ]));
    }

    /**
     * The other direction: a policy for a table that does not exist, or a
     * column that was renamed, is dead weight that LOOKS like coverage.
     * The eraser guards each one with `Schema::hasColumn` so it cannot
     * throw, which is right at runtime and would hide a stale entry here.
     */
    public function test_every_policy_names_a_column_that_actually_exists(): void
    {
        foreach (EmployeeEraser::DEPENDENTS as $table => $column) {
            $this->assertTrue(Schema::hasTable($table), "DEPENDENTS names a missing table: {$table}.");
            $this->assertTrue(Schema::hasColumn($table, $column), "DEPENDENTS names a missing column: {$table}.{$column}.");
        }

        foreach (EmployeeEraser::REFERENCES as $reference) {
            $this->assertTrue(Schema::hasTable($reference['table']), "REFERENCES names a missing table: {$reference['table']}.");
            $this->assertTrue(
                Schema::hasColumn($reference['table'], $reference['column']),
                "REFERENCES names a missing column: {$reference['table']}.{$reference['column']}."
            );

            if (isset($reference['name_column'])) {
                $this->assertTrue(
                    Schema::hasColumn($reference['table'], $reference['name_column']),
                    "REFERENCES names a missing tombstone column: {$reference['table']}.{$reference['name_column']}."
                );
            }
        }
    }

    /**
     * Soft deletion is gone from `Employee` on purpose (ADR 055), and
     * re-adding it would restore the defect rather than the safety: the
     * unique indexes on `(company_id, employee_id)` and `(company_id,
     * nik)` do not know about `deleted_at`, so a deleted employee would
     * go back to blocking the import of their own replacement.
     */
    public function test_an_employee_cannot_be_soft_deleted_again_without_a_deliberate_decision(): void
    {
        $this->assertFalse(
            Schema::hasColumn('employees', 'deleted_at'),
            'employees.deleted_at is back. Read ADR 055 before keeping it.'
        );

        $this->assertFalse(
            method_exists(Employee::class, 'getDeletedAtColumn'),
            'Employee uses SoftDeletes again. Read ADR 055 before keeping it.'
        );
    }
}
