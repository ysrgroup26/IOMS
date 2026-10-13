<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\KpiCategory;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE ONE IRREVERSIBLE STEP IN THIS RELEASE, EXERCISED.
 *
 * `2026_12_21_100200_employee_deletion_becomes_permanent` deletes rows. It
 * has to: `Employee` loses `SoftDeletes`, so an installation that already
 * soft-deleted people is left with two bad options and no third one --
 * keep `deleted_at` and those rows go on blocking imports, or drop it and
 * every employee the customer ever deleted REAPPEARS in their active list.
 *
 * A migration that deletes customer data gets a test. What is asserted is
 * not only that the purge happens, but its edges:
 *
 *  - only rows with `deleted_at` set, never a live employee;
 *  - the dependent records of those rows, and nothing else's;
 *  - documents keep themselves AND the name, so a permit printed after
 *    the deploy reads the same as one printed before it;
 *  - departments, positions and the rest of the organisation untouched --
 *    the owner's instruction was explicit that no fix may tidy those away.
 *
 * IT RUNS AGAINST ITS OWN DATABASE, and that is not fussiness. The test
 * has to put `employees.deleted_at` BACK before it can exercise the
 * purge, and that is DDL: inside MySQL a schema change commits the
 * surrounding transaction implicitly, so a `RefreshDatabase` rollback
 * would leave both the column and this fixture's rows behind for every
 * test that follows. So a second in-memory connection is migrated from
 * scratch and made the default for the duration, and the suite's own
 * database is never touched.
 */
class EmployeeSoftDeletePurgeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private Company $yard;

    private User $admin;

    private Department $department;

    private const PROBE = 'purge_probe';

    private ?string $previousConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        /*
         * A second in-memory SQLite database, migrated from scratch, made
         * the default for this test only. Everything below -- including
         * the migration under test, which uses the DEFAULT connection as
         * any migration does -- then runs against it.
         */
        config(['database.connections.'.self::PROBE => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        // One connection instance for the whole test: a fresh `:memory:`
        // database exists only as long as its connection does.
        DB::purge(self::PROBE);
        DB::connection(self::PROBE)->getPdo();

        Artisan::call('migrate', [
            '--database' => self::PROBE,
            '--force' => true,
        ]);

        $this->previousConnection = config('database.default');
        config(['database.default' => self::PROBE]);
        DB::setDefaultConnection(self::PROBE);

        $tenant = Tenant::create([
            'name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->yard = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Utama', 'code' => 'YU', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        app(CurrentTenant::class)->set($tenant->fresh());

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);

        $this->department = Department::create(['company_id' => $this->yard->id, 'name' => 'Produksi']);
    }

    protected function tearDown(): void
    {
        if ($this->previousConnection !== null) {
            config(['database.default' => $this->previousConnection]);
            DB::setDefaultConnection($this->previousConnection);
        }

        DB::purge(self::PROBE);

        parent::tearDown();
    }

    /** Puts the schema back the way it was before this release. */
    private function restoreTheSoftDeleteColumn(): void
    {
        Schema::table('employees', function ($table) {
            $table->softDeletes();
        });
    }

    private function runTheMigration(): void
    {
        $migration = require database_path('migrations/2026_12_21_100200_employee_deletion_becomes_permanent.php');

        $migration->up();
    }

    private function employee(string $number, string $name): Employee
    {
        return Employee::create([
            'employee_id' => $number,
            'full_name' => $name,
            'company_id' => $this->yard->id,
            'department_id' => $this->department->id,
            'position_id' => Position::firstOrCreate(['company_id' => $this->yard->id, 'name' => 'Welder'])->id,
            'status' => 'active',
        ]);
    }

    private function kpiFor(Employee $employee): int
    {
        $category = KpiCategory::firstOrCreate(
            ['company_id' => $this->yard->id, 'code' => 'ATT'],
            ['name' => 'Attendance', 'short_label' => 'ATT']
        );

        return DB::table('kpi_records')->insertGetId([
            'employee_id' => $employee->id,
            'department_id' => $this->department->id,
            'kpi_category_id' => $category->id,
            'record_date' => now()->subDay()->toDateString(),
            'month' => (int) now()->format('m'),
            'year' => (int) now()->format('Y'),
            'quantity' => 1,
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_purges_only_the_employees_that_were_already_deleted(): void
    {
        $this->restoreTheSoftDeleteColumn();

        $gone = $this->employee('EMP-GONE', 'Sudah Dihapus');
        $goneKpi = $this->kpiFor($gone);

        $kept = $this->employee('EMP-KEPT', 'Masih Bekerja');
        $keptKpi = $this->kpiFor($kept);

        $permitId = DB::table('permits_to_work')->insertGetId([
            'ptw_number' => 'PTW-OLD',
            'company_id' => $this->yard->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Pengelasan lambung.',
            'start_datetime' => now()->subDays(30),
            'end_datetime' => now()->subDays(30)->addHours(8),
            'requested_by' => $this->admin->id,
            'pic_employee_id' => $gone->id,
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The state this migration exists for: a row an administrator
        // already removed, still sitting in the table holding its number.
        DB::table('employees')->where('id', $gone->id)->update(['deleted_at' => now()]);

        $departments = DB::table('departments')->count();
        $positions = DB::table('positions')->count();

        $this->runTheMigration();

        // 1. the already-deleted employee and its dependents are gone
        $this->assertDatabaseMissing('employees', ['id' => $gone->id]);
        $this->assertDatabaseMissing('kpi_records', ['id' => $goneKpi]);

        // 2. the live employee is untouched, dependents included
        $this->assertDatabaseHas('employees', ['id' => $kept->id]);
        $this->assertDatabaseHas('kpi_records', ['id' => $keptKpi]);

        // 3. the document survives and still names the person
        $permit = DB::table('permits_to_work')->where('id', $permitId)->first();

        $this->assertNotNull($permit, 'A closed permit was deleted by an HR migration.');
        $this->assertNull($permit->pic_employee_id);
        $this->assertSame('Sudah Dihapus', $permit->pic_name);

        // 4. the organisation's own master data is not touched
        $this->assertSame($departments, DB::table('departments')->count());
        $this->assertSame($positions, DB::table('positions')->count());

        // 5. and the column is gone, so nothing can soft delete again
        $this->assertFalse(Schema::hasColumn('employees', 'deleted_at'));
    }

    /**
     * The ordinary installation -- nobody was ever soft-deleted -- and a
     * retried deploy, which runs the migration a second time.
     *
     * Both in one test, on the same database, because provisioning a
     * migrated database is the expensive part and the second phase needs
     * the state the first leaves behind anyway.
     */
    public function test_it_changes_no_data_when_nobody_was_deleted_and_is_safe_to_re_run(): void
    {
        $this->restoreTheSoftDeleteColumn();

        $kept = $this->employee('EMP-1', 'Masih Bekerja');
        $keptKpi = $this->kpiFor($kept);

        $this->runTheMigration();

        $this->assertDatabaseHas('employees', ['id' => $kept->id]);
        $this->assertDatabaseHas('kpi_records', ['id' => $keptKpi]);
        $this->assertFalse(Schema::hasColumn('employees', 'deleted_at'));

        /*
         * A deploy that fails part way and is retried runs this again.
         * Guarded on `Schema::hasColumn`, so the second run is a no-op
         * rather than an error that stops the rest of the deploy.
         */
        $this->runTheMigration();

        $this->assertDatabaseHas('employees', ['id' => $kept->id]);
        $this->assertDatabaseHas('kpi_records', ['id' => $keptKpi]);
        $this->assertFalse(Schema::hasColumn('employees', 'deleted_at'));
    }
}
