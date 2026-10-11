<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * THE ROWS THE OLD IMPORTER ALREADY WROTE.
 *
 * Fixing the parser does not fix the data it produced. Any database that
 * ran an employee import before v2.108.0 may hold join dates of
 * 1970-01-01 that nobody typed, and they do not look wrong — they look
 * like a date.
 *
 * `employees:audit-join-dates` is the recovery procedure, and what it
 * REFUSES to do is the important part: it never infers what the date
 * should have been. IOMS keeps no record of the original cell (activity
 * logs store counts, not values), so the only trustworthy source is the
 * customer's own spreadsheet. The command finds and names the rows;
 * `--clear` removes the fabricated value and nothing else.
 */
class FabricatedJoinDateRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Company $yard;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $tenant = Tenant::create([
            'name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->yard = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Utama', 'code' => 'YU', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);
    }

    private function employee(string $id, ?string $joinDate): Employee
    {
        return Employee::withoutGlobalScopes()->create([
            'employee_id' => $id,
            'full_name' => 'Orang '.$id,
            'company_id' => $this->yard->id,
            'status' => 'active',
            'join_date' => $joinDate,
            'phone' => '0811',
            'email' => $id.'@yard.test',
        ]);
    }

    public function test_it_reports_nothing_on_a_clean_database(): void
    {
        $this->employee('EMP-1', '2026-01-15');

        $this->artisan('employees:audit-join-dates')
            ->expectsOutputToContain('No employee carries a join date of 1970-01-01')
            ->assertSuccessful();
    }

    public function test_it_names_every_affected_employee(): void
    {
        $this->employee('EMP-BAD', '1970-01-01');
        $this->employee('EMP-GOOD', '2026-01-15');

        $this->artisan('employees:audit-join-dates')
            ->expectsOutputToContain('1 employee(s) carry a join date of 1970-01-01')
            ->expectsOutputToContain('EMP-BAD')
            ->assertSuccessful();
    }

    public function test_reporting_changes_nothing(): void
    {
        $employee = $this->employee('EMP-BAD', '1970-01-01');

        $this->artisan('employees:audit-join-dates')->assertSuccessful();

        $this->assertSame('1970-01-01', $employee->fresh()->join_date?->toDateString());
    }

    public function test_clearing_requires_confirmation_and_declining_changes_nothing(): void
    {
        $employee = $this->employee('EMP-BAD', '1970-01-01');

        $this->artisan('employees:audit-join-dates --clear')
            ->expectsConfirmation('Set 1 join date(s) to NULL? No other field is touched.', 'no')
            ->expectsOutputToContain('Nothing was changed.')
            ->assertSuccessful();

        $this->assertSame('1970-01-01', $employee->fresh()->join_date?->toDateString());
    }

    public function test_clearing_empties_only_the_fabricated_date(): void
    {
        $bad = $this->employee('EMP-BAD', '1970-01-01');
        $good = $this->employee('EMP-GOOD', '2026-01-15');

        $this->artisan('employees:audit-join-dates --clear')
            ->expectsConfirmation('Set 1 join date(s) to NULL? No other field is touched.', 'yes')
            ->assertSuccessful();

        $this->assertNull($bad->fresh()->join_date);
        $this->assertSame('2026-01-15', $good->fresh()->join_date?->toDateString(), 'A real date must not be touched.');
    }

    /** "Never overwrite unrelated employee fields" — asserted, not assumed. */
    public function test_clearing_touches_no_other_field(): void
    {
        $employee = $this->employee('EMP-BAD', '1970-01-01');
        $before = $employee->fresh()->only(['employee_id', 'full_name', 'company_id', 'status', 'phone', 'email', 'employment_type']);

        $this->artisan('employees:audit-join-dates --clear')
            ->expectsConfirmation('Set 1 join date(s) to NULL? No other field is touched.', 'yes')
            ->assertSuccessful();

        $after = $employee->fresh()->only(['employee_id', 'full_name', 'company_id', 'status', 'phone', 'email', 'employment_type']);

        $this->assertSame($before, $after);
    }

    public function test_the_clearing_is_recorded_in_the_audit_trail(): void
    {
        $this->employee('EMP-BAD', '1970-01-01');

        $this->artisan('employees:audit-join-dates --clear')
            ->expectsConfirmation('Set 1 join date(s) to NULL? No other field is touched.', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('activity_logs', ['action' => 'updated']);
    }

    public function test_a_removed_employee_is_included_so_a_restore_is_not_poisoned(): void
    {
        $employee = $this->employee('EMP-GONE', '1970-01-01');
        $employee->delete();

        $this->artisan('employees:audit-join-dates --clear')
            ->expectsConfirmation('Set 1 join date(s) to NULL? No other field is touched.', 'yes')
            ->assertSuccessful();

        $this->assertNull(Employee::withoutGlobalScopes()->withTrashed()->find($employee->id)->join_date);
    }

    public function test_the_sweep_can_be_narrowed_to_one_import_window(): void
    {
        $old = $this->employee('EMP-OLD', '1970-01-01');
        $old->forceFill(['created_at' => now()->subYear()])->saveQuietly();

        $recent = $this->employee('EMP-RECENT', '1970-01-01');

        $this->artisan('employees:audit-join-dates --since='.now()->subMonth()->toDateString().' --clear')
            ->expectsConfirmation('Set 1 join date(s) to NULL? No other field is touched.', 'yes')
            ->assertSuccessful();

        $this->assertNull($recent->fresh()->join_date);
        $this->assertSame('1970-01-01', $old->fresh()->join_date?->toDateString(), 'Outside the window, so untouched.');
    }
}
