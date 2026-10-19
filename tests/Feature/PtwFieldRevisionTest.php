<?php

namespace Tests\Feature;

use App\Concerns\HasWorkflow;
use App\Http\Middleware\RestrictMyWorkUser;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\PermitToWork;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * v2.125.0 -- THE PTW AND FIELD ACCESS REVISION, PINNED.
 *
 * One test class for the five defects this release fixed, because they are
 * one story: a field worker could not name a colleague on a permit, the
 * permit did not know who was responsible for the work, the notification
 * telling them it came back went nowhere, and the words it used were PHP
 * class names.
 *
 * Every assertion here failed before the release. They are written as the
 * SYMPTOM rather than the implementation wherever possible -- "the lookup
 * answers 200 for a My Work account" rather than "the constant contains a
 * string" -- so a different fix that preserved the behaviour would keep
 * them green and a regression could not.
 *
 * @see docs/kb/backlog/PTW and Field Access Master Revision.md
 */
class PtwFieldRevisionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Yard', 'slug' => 'yard-ptwrev']);
        $this->company = Company::withoutGlobalScopes()->create(['name' => 'Yard Co', 'tenant_id' => $this->tenant->id]);

        $department = Department::withoutGlobalScopes()->create([
            'name' => 'Fabrication', 'company_id' => $this->company->id,
        ]);

        $this->employee = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-10244',
            'full_name' => 'Misrani Saputra',
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'status' => 'active',
        ]);
    }

    private function myWorkUser(bool $ptwAccess = true): User
    {
        $user = User::create([
            'name' => 'Misrani Saputra',
            'email' => null,
            'password' => bcrypt('field-code'),
            'role' => 'employee',
            'user_type' => 'my_work',
            'is_field_user' => true,
            'ptw_access' => $ptwAccess,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        // `employee_id` is deliberately NOT mass-assignable -- which
        // account IS which person is not something a request may set.
        // MyWorkAccessService::createFor() force-fills it for the same
        // reason; this mirrors it rather than relaxing the model.
        $user->forceFill(['employee_id' => $this->employee->id])->save();

        return $user->refresh();
    }

    private function hseUser(): User
    {
        return User::create([
            'name' => 'Sri Handayani', 'email' => 'hse@ptwrev.test', 'password' => bcrypt('x'),
            'role' => 'hse', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | D -- the personnel search
    |--------------------------------------------------------------------------
    */

    /**
     * THE DEFECT ITSELF. A My Work account filling in a permit searched the
     * directory, got a 403, and the component rendered the refusal as
     * "Karyawan tidak ditemukan." -- a permission failure displayed as a
     * statement about the workforce.
     */
    public function test_a_my_work_account_may_read_the_employee_lookup_its_permit_form_depends_on(): void
    {
        $response = $this->actingAs($this->myWorkUser())
            ->getJson('/employee-lookup?q=Misrani');

        $response->assertOk();
        $this->assertSame('Misrani Saputra', $response->json('data.0.full_name'));
    }

    /**
     * The allow-list and the form must not be able to drift apart again:
     * every route the permit form fetches from has to be on it.
     */
    public function test_the_permit_form_and_the_directory_it_reads_are_both_allowed(): void
    {
        foreach (['permits-to-work', 'employee-lookup'] as $prefix) {
            $this->assertContains(
                $prefix,
                RestrictMyWorkUser::allowedPrefixes(),
                "A My Work account raises permits, so `{$prefix}` must be reachable. ".
                'Offering a form and refusing the data it cannot be filled in without is the ADR 061 defect.'
            );
        }
    }

    /** Tenant isolation was NOT the fix and must still hold. */
    public function test_the_lookup_never_reaches_another_tenants_employees(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-ptwrev']);
        $otherCompany = Company::withoutGlobalScopes()->create(['name' => 'Other Co', 'tenant_id' => $other->id]);
        Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-99999', 'full_name' => 'Misrani Rahardjo',
            'company_id' => $otherCompany->id, 'status' => 'active',
        ]);

        $names = collect($this->actingAs($this->myWorkUser())
            ->getJson('/employee-lookup?q=Misrani')->json('data'))
            ->pluck('full_name');

        $this->assertContains('Misrani Saputra', $names);
        $this->assertNotContains('Misrani Rahardjo', $names);
    }

    /**
     * What a person actually types. Each of these returned nothing from the
     * old single contiguous `LIKE %term%`.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('realWorldSearchTerms')]
    public function test_a_name_is_found_however_a_person_types_it(string $term): void
    {
        $found = collect($this->actingAs($this->myWorkUser())
            ->getJson('/employee-lookup?q='.urlencode($term))->json('data'))
            ->pluck('full_name');

        $this->assertContains('Misrani Saputra', $found, "Searching \"{$term}\" found nobody.");
    }

    public static function realWorldSearchTerms(): array
    {
        return [
            'exact' => ['Misrani Saputra'],
            'one token' => ['Misrani'],
            'lower case' => ['misrani'],
            'family name first' => ['Saputra Misrani'],
            'doubled internal space' => ['Misrani  Saputra'],
            'leading and trailing space' => ['  Misrani  '],
            'name and badge together' => ['Misrani EMP-10244'],
            'badge alone' => ['EMP-10244'],
        ];
    }

    /** More tokens narrow, they do not widen: a wrong second token finds nobody. */
    public function test_an_extra_token_that_does_not_match_narrows_to_nothing(): void
    {
        $found = $this->actingAs($this->myWorkUser())
            ->getJson('/employee-lookup?q='.urlencode('Misrani Nonexistent'))->json('data');

        $this->assertSame([], $found);
    }

    /*
    |--------------------------------------------------------------------------
    | C -- the default Penanggung Jawab Pekerjaan
    |--------------------------------------------------------------------------
    */

    public function test_the_create_form_defaults_the_pic_to_the_creators_own_employee_record(): void
    {
        $this->actingAs($this->myWorkUser())
            ->get('/permits-to-work/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('defaultPicEmployeeId', (string) $this->employee->id));
    }

    /** An account with no employee link gets no default rather than a guess. */
    public function test_an_account_without_an_employee_link_gets_an_empty_default(): void
    {
        $this->actingAs($this->hseUser())
            ->get('/permits-to-work/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('defaultPicEmployeeId', ''));
    }

    /**
     * THE RULE THE DEFAULT MUST NOT BREAK. Being named PIC, or creating the
     * permit at all, grants no approval authority -- that stays
     * `canManageHse()`.
     */
    public function test_being_the_creator_and_the_pic_grants_no_approval_authority(): void
    {
        $creator = $this->myWorkUser();

        $this->assertTrue($creator->canCreatePtw(), 'PTW Access is what lets them raise one.');
        $this->assertFalse($creator->canManageHse(), 'And it must not be what lets them approve one.');
    }

    /*
    |--------------------------------------------------------------------------
    | G -- notifications that actually go somewhere
    |--------------------------------------------------------------------------
    */

    private function permit(string $status, User $requester): PermitToWork
    {
        return PermitToWork::create([
            'ptw_number' => 'PTW-REV-'.uniqid(),
            'company_id' => $this->company->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Welding on the main deck.',
            'start_datetime' => Carbon::parse('2026-10-01 01:00:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-10-01 09:00:00', 'UTC'),
            'requested_by' => $requester->id,
            'status' => $status,
        ]);
    }

    /**
     * THE DEFECT ITSELF. Every workflow notification was created with a
     * null url, so the menu -- which navigates only when one is set -- had
     * nothing to open. The notification appeared, the tap marked it read,
     * and nothing happened.
     */
    public function test_a_status_change_notification_carries_the_permits_own_address(): void
    {
        $requester = $this->myWorkUser();
        $permit = $this->permit(PermitToWork::STATUS_SUBMITTED, $requester);

        $permit->transitionTo(PermitToWork::STATUS_REJECTED, $this->hseUser(), null, ['comments' => 'Area belum diisolasi.']);

        $notification = Notification::where('user_id', $requester->id)->latest()->first();

        $this->assertNotNull($notification, 'The requester must be told their permit came back.');
        $this->assertSame(route('permits-to-work.show', $permit), $notification->url);
        $this->assertSame('permits-to-work.show', $notification->meta['route'] ?? null);
        $this->assertSame('Area belum diisolasi.', $notification->body, 'The reason it came back IS the notification.');
    }

    /** No PHP class name may reach somebody holding a phone. */
    public function test_a_notification_names_the_document_in_the_products_own_words(): void
    {
        $requester = $this->myWorkUser();
        $permit = $this->permit(PermitToWork::STATUS_SUBMITTED, $requester);

        $permit->transitionTo(PermitToWork::STATUS_APPROVED, $this->hseUser());

        $title = Notification::where('user_id', $requester->id)->latest()->value('title');

        $this->assertStringNotContainsString('PermitToWork', $title);
        $this->assertStringContainsString('Permit to Work', $title);
        $this->assertStringContainsString($permit->ptw_number, $title, 'Which permit is the first thing asked.');
    }

    /**
     * A status with an underscore used to render as the column
     * ("In_progress") -- the same root cause already fixed for the activity
     * description, missed here.
     */
    public function test_a_multi_word_status_is_rendered_as_language(): void
    {
        $permit = $this->permit(PermitToWork::STATUS_SUBMITTED, $this->myWorkUser());

        $this->assertSame('Under Review', $permit->statusLabel('under_review'));
        $this->assertSame('Submitted', $permit->statusLabel());
    }

    /**
     * Every workflow model in the product now names a destination, because
     * "notifications do nothing" was never only a PTW problem.
     */
    public function test_every_workflow_model_names_a_real_destination_route(): void
    {
        $missing = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! in_array(HasWorkflow::class, class_uses_recursive($class), true)) {
                continue;
            }

            $route = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->notificationRouteName();

            if ($route === null || ! \Illuminate\Support\Facades\Route::has($route)) {
                $missing[] = basename($file, '.php').' => '.var_export($route, true);
            }
        }

        $this->assertSame(
            [],
            $missing,
            "A workflow model whose notifications have no destination sends its users a dead row.\n  - "
            .implode("\n  - ", $missing)."\n"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | K -- the job title on the stamp
    |--------------------------------------------------------------------------
    */

    /**
     * The stamp used to show the IOMS SYSTEM role ("Hse", "Super Admin").
     * The authority a reader on site recognises is the company's own job
     * title, and which title signs which permit is the customer's
     * configuration.
     */
    public function test_the_approval_stamp_shows_the_companys_own_job_title(): void
    {
        $position = Position::withoutGlobalScopes()->create([
            'name' => 'SPV HSE', 'company_id' => $this->company->id,
        ]);

        $officer = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-20100', 'full_name' => 'Sri Handayani',
            'company_id' => $this->company->id, 'position_id' => $position->id, 'status' => 'active',
        ]);

        $hse = $this->hseUser();
        $hse->forceFill(['employee_id' => $officer->id])->save();

        $permit = $this->permit(PermitToWork::STATUS_SUBMITTED, $this->myWorkUser());

        // Approved through the real endpoint, deliberately: that is the
        // only context in which the acting account's company is resolved,
        // and therefore the only honest way to assert what the stamp
        // records. Driving the model directly would pass while production
        // stored nothing.
        $this->actingAs($hse)
            ->post('/permits-to-work/'.$permit->id.'/transition', ['status' => PermitToWork::STATUS_APPROVED])
            ->assertRedirect();

        $this->actingAs($hse)
            ->get('/permits-to-work/'.$permit->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('authorization.role', 'SPV HSE'));
    }

    /**
     * AND IT IS A FACT ABOUT THE PAST. Renaming the position, or moving the
     * officer out of it, must not rewrite a permit already signed -- the
     * identical defect v2.100.0 fixed for the approver's NAME.
     */
    public function test_renaming_a_position_later_does_not_rewrite_a_signed_permit(): void
    {
        $position = Position::withoutGlobalScopes()->create([
            'name' => 'Manager Shipyard', 'company_id' => $this->company->id,
        ]);

        $manager = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-20200', 'full_name' => 'Paten Wibowo',
            'company_id' => $this->company->id, 'position_id' => $position->id, 'status' => 'active',
        ]);

        $hse = $this->hseUser();
        $hse->forceFill(['employee_id' => $manager->id])->save();

        $permit = $this->permit(PermitToWork::STATUS_SUBMITTED, $this->myWorkUser());

        $this->actingAs($hse)
            ->post('/permits-to-work/'.$permit->id.'/transition', ['status' => PermitToWork::STATUS_APPROVED])
            ->assertRedirect();

        $this->assertSame('Manager Shipyard', ActivityLog::where('subject_type', PermitToWork::class)
            ->where('subject_id', $permit->id)
            ->where('action', PermitToWork::STATUS_APPROVED)
            ->value('actor_title'));

        // The company reorganises afterwards.
        $position->update(['name' => 'Head of Yard Operations']);

        $this->actingAs($hse)
            ->get('/permits-to-work/'.$permit->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('authorization.role', 'Manager Shipyard'));
    }

    /*
    |--------------------------------------------------------------------------
    | B -- the two quotas are two quotas
    |--------------------------------------------------------------------------
    */

    /**
     * Granting PTW Access must not consume a My Work seat, and the three
     * concepts must stay three. The failure this guards against is
     * commercial: a tenant paying for one thing being charged for another.
     */
    public function test_ptw_access_a_landing_preference_and_the_account_class_are_three_separate_facts(): void
    {
        $entitlements = app(\App\Services\EntitlementService::class);

        // A FULL account that lands in My Work and may raise permits.
        $full = User::create([
            'name' => 'Supervisor', 'email' => 'spv@ptwrev.test', 'password' => bcrypt('x'),
            'role' => 'manager', 'user_type' => 'full', 'is_field_user' => true, 'ptw_access' => true,
            'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $this->assertFalse($full->isMyWorkUser(), 'Landing in My Work is not being a My Work account.');
        $this->assertTrue($full->isFieldUser(), 'And the landing preference still applies to a full user.');
        $this->assertTrue($full->canCreatePtw());

        $this->assertSame(
            0,
            $entitlements->myWorkUsersUsedCount($this->tenant),
            'Granting PTW Access consumed a My Work seat. The two quotas are priced separately.'
        );

        // The My Work class is what the My Work seat counts.
        $this->myWorkUser();
        $this->assertSame(1, $entitlements->myWorkUsersUsedCount($this->tenant->fresh()));
    }

    /** Revoking PTW Access revokes the permission and touches neither other fact. */
    public function test_revoking_ptw_access_leaves_the_class_and_the_landing_preference_alone(): void
    {
        $user = $this->myWorkUser();

        $user->update(['ptw_access' => false]);
        $user->refresh();

        $this->assertFalse($user->canCreatePtw());
        $this->assertTrue($user->isMyWorkUser());
        $this->assertTrue($user->isFieldUser());
    }
}
