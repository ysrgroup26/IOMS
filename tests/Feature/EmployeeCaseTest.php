<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCase;
use App\Models\EmployeeCaseAction;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * v2.69.0 -- Employee Cases.
 *
 * The rules worth protecting here are not CRUD. They are:
 *
 *   1. STANDING IS DERIVED, so it lapses on its own. If this ever becomes
 *      a stored column, these tests fail -- which is the point.
 *   2. CONFIDENTIALITY. A disciplinary record is not readable by everyone
 *      who can read the employee list, and that includes not leaking it
 *      through the employee profile's prop payload.
 *   3. TENANT ISOLATION, on a table holding the most sensitive HR data in
 *      the product.
 *   4. THE LIFECYCLE GUARD, specifically that a sanction cannot be issued
 *      on a case nobody reviewed.
 */
class EmployeeCaseTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->company, $this->hr, $this->employee] = $this->makeTenant('Alpha', 'ALFA');
        $this->actingAsTenant($this->hr);
    }

    /* ==============================================================
     * 1. STANDING IS DERIVED, NEVER STORED
     * ============================================================== */

    public function test_an_employee_with_no_actions_has_a_clear_standing(): void
    {
        $this->assertNull($this->employee->currentDisciplinaryStanding());
    }

    public function test_the_most_severe_action_still_in_force_is_the_standing(): void
    {
        $case = $this->makeCase();

        $this->issueAction($case, EmployeeCaseAction::TYPE_SP1, now()->subMonth(), now()->addMonths(5));
        $this->issueAction($case, EmployeeCaseAction::TYPE_VERBAL_WARNING, now()->subDays(3), now()->addMonths(6));

        $standing = $this->employee->fresh()->currentDisciplinaryStanding();

        $this->assertSame(EmployeeCaseAction::TYPE_SP1, $standing->type);
    }

    /**
     * The reason this module stores a window instead of a level: an SP is
     * valid for a fixed term, and a stored "current level" would be right
     * on the day it was written and wrong every day after.
     */
    public function test_a_lapsed_action_stops_counting_without_anything_running(): void
    {
        $case = $this->makeCase();
        $this->issueAction($case, EmployeeCaseAction::TYPE_SP2, now()->subMonths(8), now()->subDay());

        $this->assertNull(
            $this->employee->fresh()->currentDisciplinaryStanding(),
            'An action whose validity window has passed must stop affecting standing on its own, with no '
            .'scheduled job and no stored flag to update.'
        );
    }

    public function test_an_action_with_no_expiry_stays_in_force(): void
    {
        $case = $this->makeCase();
        $this->issueAction($case, EmployeeCaseAction::TYPE_TERMINATION, now()->subYears(2), null);

        $this->assertSame(
            EmployeeCaseAction::TYPE_TERMINATION,
            $this->employee->fresh()->currentDisciplinaryStanding()->type,
            'A null effective_until means "does not lapse", not "unknown".'
        );
    }

    /* ==============================================================
     * 2. THE LIFECYCLE GUARD
     * ============================================================== */

    public function test_a_sanction_cannot_be_issued_on_a_case_nobody_reviewed(): void
    {
        $case = $this->makeCase();

        $this->assertFalse(
            $case->canTransitionTo(EmployeeCase::STATUS_ACTION_ISSUED),
            'action_issued must be reachable only from under_review -- skipping review is the shortcut this '
            .'record exists to prevent.'
        );

        $this->expectException(ValidationException::class);
        $case->transitionTo(EmployeeCase::STATUS_ACTION_ISSUED, $this->hr);
    }

    public function test_issuing_an_action_moves_a_reviewed_case_to_action_issued(): void
    {
        $case = $this->makeCase();
        $case->transitionTo(EmployeeCase::STATUS_UNDER_REVIEW, $this->hr);

        $this->post(route('employee-cases.actions.store', $case), [
            'type' => EmployeeCaseAction::TYPE_SP1,
            'issued_at' => now()->toDateString(),
            'effective_until' => now()->addMonths(6)->toDateString(),
        ])->assertRedirect();

        $this->assertSame(EmployeeCase::STATUS_ACTION_ISSUED, $case->fresh()->status);
        $this->assertCount(1, $case->fresh()->actions);
    }

    public function test_an_action_cannot_expire_before_it_was_issued(): void
    {
        $case = $this->makeCase();
        $case->transitionTo(EmployeeCase::STATUS_UNDER_REVIEW, $this->hr);

        $this->post(route('employee-cases.actions.store', $case), [
            'type' => EmployeeCaseAction::TYPE_SP1,
            'issued_at' => now()->toDateString(),
            'effective_until' => now()->subMonth()->toDateString(),
        ])->assertSessionHasErrors('effective_until');
    }

    public function test_a_dismissed_case_is_not_the_same_as_a_closed_one(): void
    {
        $case = $this->makeCase();
        $case->transitionTo(EmployeeCase::STATUS_UNDER_REVIEW, $this->hr);

        $this->post(route('employee-cases.dismiss', $case), ['closure_note' => 'Tidak terbukti.'])
            ->assertRedirect();

        $fresh = $case->fresh();
        $this->assertSame(EmployeeCase::STATUS_DISMISSED, $fresh->status);
        $this->assertSame('unsubstantiated', $fresh->outcome);
        $this->assertFalse($fresh->is_active);
    }

    /* ==============================================================
     * 3. CONFIDENTIALITY
     * ============================================================== */

    public function test_a_manager_cannot_reach_employee_cases(): void
    {
        $manager = $this->makeUser('manager@alfa.test', User::ROLE_MANAGER);
        $this->actingAsTenant($manager);

        $this->get(route('employee-cases.index'))->assertForbidden();
        $this->get(route('employee-cases.show', $this->makeCaseAs($this->hr)))->assertForbidden();
    }

    public function test_an_hse_user_cannot_reach_employee_cases(): void
    {
        $hse = $this->makeUser('hse@alfa.test', User::ROLE_HSE);
        $this->actingAsTenant($hse);

        $this->get(route('employee-cases.index'))->assertForbidden();
    }

    /**
     * The leak that hiding the card in React would NOT have prevented:
     * an Inertia page ships its props to the browser whether or not a
     * component renders them.
     */
    public function test_the_employee_profile_does_not_ship_case_data_to_an_unauthorised_viewer(): void
    {
        $case = $this->makeCaseAs($this->hr);
        $case->transitionTo(EmployeeCase::STATUS_UNDER_REVIEW, $this->hr);
        $this->issueAction($case, EmployeeCaseAction::TYPE_SP3, now(), now()->addMonths(6));

        $manager = $this->makeUser('manager2@alfa.test', User::ROLE_MANAGER);
        $this->actingAsTenant($manager);

        $props = $this->get(route('employees.show', $this->employee))->viewData('page')['props'];

        $this->assertNull(
            $props['disciplinary'] ?? null,
            'Disciplinary data must not be serialized into the profile payload for a viewer who may not read '
            .'cases -- hiding the card client-side would leave the data in the page source.'
        );
        $this->assertFalse($props['can']['viewCases']);
    }

    public function test_hr_does_see_the_disciplinary_summary_on_the_profile(): void
    {
        $case = $this->makeCaseAs($this->hr);
        $case->transitionTo(EmployeeCase::STATUS_UNDER_REVIEW, $this->hr);
        $this->issueAction($case, EmployeeCaseAction::TYPE_SP1, now(), now()->addMonths(6));

        $props = $this->get(route('employees.show', $this->employee))->viewData('page')['props'];

        $this->assertTrue($props['can']['viewCases']);
        $this->assertSame(EmployeeCaseAction::TYPE_SP1, $props['disciplinary']['standing']['type']);
    }

    /* ==============================================================
     * 4. TENANT ISOLATION
     * ============================================================== */

    public function test_a_case_belonging_to_another_tenant_is_unreachable(): void
    {
        [, $otherHr, $otherEmployee] = $this->makeTenant('Beta', 'BETA');

        $this->actingAsTenant($otherHr);
        $foreignCase = EmployeeCase::create([
            'case_number' => 'EC-2026-09999',
            'company_id' => $otherEmployee->company_id,
            'employee_id' => $otherEmployee->id,
            'category' => 'conduct',
            'severity' => 'low',
            'title' => 'Other tenant case',
            'reported_at' => now()->toDateString(),
            'status' => EmployeeCase::STATUS_OPEN,
        ]);

        $this->actingAsTenant($this->hr);

        $this->get(route('employee-cases.show', $foreignCase))->assertNotFound();
        $this->post(route('employee-cases.start-review', $foreignCase))->assertNotFound();
        $this->assertNull(EmployeeCase::find($foreignCase->id), 'The global company scope must hide it from queries too.');
    }

    public function test_a_case_cannot_be_opened_against_another_tenants_employee(): void
    {
        [, , $otherEmployee] = $this->makeTenant('Gamma', 'GAMA');

        $this->actingAsTenant($this->hr);

        $this->post(route('employee-cases.store'), [
            'company_id' => $this->company->id,
            'employee_id' => $otherEmployee->id,
            'category' => 'conduct',
            'severity' => 'low',
            'title' => 'Cross-tenant attempt',
            'reported_at' => now()->toDateString(),
        ])->assertSessionHasErrors('employee_id');
    }

    /* ==============================================================
     * Fixtures
     * ============================================================== */

    /** @return array{0: Company, 1: User, 2: Employee} */
    private function makeTenant(string $name, string $code): array
    {
        $tenant = Tenant::create([
            'name' => $name, 'slug' => strtolower($code).'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => $name, 'code' => $code, 'is_active' => true,
        ]);

        $hr = User::create([
            'name' => "$name HR",
            'email' => strtolower($code).'-'.uniqid().'@example.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_HRD,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        $department = Department::create(['company_id' => $company->id, 'name' => "$name Ops", 'is_active' => true]);
        $position = Position::create([
            'company_id' => $company->id, 'department_id' => $department->id, 'name' => "$name Fitter", 'is_active' => true,
        ]);

        $employee = Employee::create([
            'company_id' => $company->id, 'department_id' => $department->id, 'position_id' => $position->id,
            'employee_id' => "$code-0001", 'full_name' => "$name Worker", 'status' => 'active',
        ]);

        return [$company, $hr, $employee];
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'name' => $role.' user',
            'email' => uniqid().'-'.$email,
            'password' => bcrypt('secret-pass-1'),
            'role' => $role,
            'tenant_id' => $this->hr->tenant_id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
    }

    private function makeCase(): EmployeeCase
    {
        return $this->makeCaseAs($this->hr);
    }

    private function makeCaseAs(User $user): EmployeeCase
    {
        return EmployeeCase::create([
            'case_number' => 'EC-2026-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'company_id' => $this->company->id,
            'employee_id' => $this->employee->id,
            'category' => 'conduct',
            'severity' => 'medium',
            'title' => 'Repeated late arrival',
            'reported_at' => now()->toDateString(),
            'reported_by' => $user->id,
            'status' => EmployeeCase::STATUS_OPEN,
        ]);
    }

    private function issueAction(EmployeeCase $case, string $type, $issuedAt, $until): EmployeeCaseAction
    {
        return $case->actions()->create([
            'type' => $type,
            'issued_at' => $issuedAt,
            'effective_until' => $until,
            'issued_by' => $this->hr->id,
        ]);
    }

    private function actingAsTenant(User $user): void
    {
        $this->be($user);
        app(CurrentTenant::class)->set($user->tenant);
    }
}
