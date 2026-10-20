<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Position;
use App\Models\PtwWorkflowSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NotificationService;
use App\Services\PtwWorkflowService;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\PpeTypeSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.127.0 -- TWO GATES, IN ORDER, HELD BY DIFFERENT PEOPLE.
 *
 * A Permit to Work is verified by HSE and then approved by somebody else,
 * and only then may it become ACTIVE. The order is the product rule, and
 * most of the interesting assertions here are about what must NOT happen:
 * the approver hearing about a permit before it is verified, a verifier
 * approving their own verification, a second presser overwriting the first,
 * and an approval surviving a notification that fails.
 *
 * @see docs/ADR/067-a-permit-is-verified-and-then-approved.md
 */
class PtwTwoStageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $requester;

    private User $verifier;

    private User $approver;

    private User $bystander;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);
        $this->seed(PpeTypeSeeder::class);

        [$this->tenant, $this->company] = $this->makeTenant('a');

        $this->requester = $this->user('foreman', User::ROLE_MANAGER, $this->tenant, $this->company);
        // `canCreatePtw()` is `canManageHse() OR ptw_access` -- a manager
        // has neither by default, and raising a permit is the one thing
        // this fixture's requester exists to do.
        $this->requester->update(['ptw_access' => true]);
        $this->verifier = $this->user('verifier', User::ROLE_HSE, $this->tenant, $this->company);
        $this->approver = $this->user('approver', User::ROLE_MANAGER, $this->tenant, $this->company);
        // Holds neither gate: HRD is on no stage's default list.
        $this->bystander = $this->user('bystander', User::ROLE_HRD, $this->tenant, $this->company);

        $this->route($this->company, verify: [User::ROLE_HSE], approve: [], approveUsers: [$this->approver->id]);
    }

    /** @return array{0: Tenant, 1: Company} */
    private function makeTenant(string $suffix): array
    {
        $package = Package::where('slug', 'business')->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$suffix, 'slug' => 'ptw2-'.$suffix.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit '.$suffix, 'code' => strtoupper($suffix).'U', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($tenant->fresh());
        Department::create(['company_id' => $company->id, 'name' => 'HSE']);

        return [$tenant, $company];
    }

    private function user(string $slug, string $role, Tenant $tenant, Company $company): User
    {
        return User::create([
            'name' => ucfirst($slug), 'email' => $slug.'-'.uniqid().'@ptw2.test',
            'password' => bcrypt('secret-pass-1'), 'role' => $role,
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true,
        ]);
    }

    /** The company's own routing for the two gates. */
    private function route(Company $company, array $verify = [], array $approve = [], array $verifyUsers = [], array $approveUsers = []): void
    {
        PtwWorkflowSetting::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => $company->id],
            [
                'verify_roles' => $verify,
                'verify_user_ids' => $verifyUsers,
                'approve_roles' => $approve,
                'approve_user_ids' => $approveUsers,
            ],
        );
    }

    private function permit(string $status = PermitToWork::STATUS_SUBMITTED, ?Company $company = null): PermitToWork
    {
        return PermitToWork::withoutGlobalScopes()->create([
            'ptw_number' => 'PTW-2S-'.uniqid(),
            'company_id' => ($company ?? $this->company)->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Welding on the main deck.',
            'start_datetime' => Carbon::parse('2026-11-01 01:00:00', 'UTC'),
            'end_datetime' => Carbon::parse('2026-11-01 09:00:00', 'UTC'),
            'requested_by' => $this->requester->id,
            'status' => $status,
        ]);
    }

    private function transition(User $actor, PermitToWork $permit, string $status, array $extra = [])
    {
        return $this->actingAs($actor)
            ->post('/permits-to-work/'.$permit->id.'/transition', array_merge(['status' => $status], $extra));
    }

    /** @return \Illuminate\Support\Collection<int, Notification> */
    private function notificationsFor(User $user)
    {
        return Notification::where('user_id', $user->id)->get();
    }

    /*
    |--------------------------------------------------------------------------
    | 1-2. Submission tells the verifier, and ONLY the verifier
    |--------------------------------------------------------------------------
    */

    public function test_a_submitted_permit_waits_for_verification_and_is_not_active(): void
    {
        $permit = $this->permit();

        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->status);
        $this->assertSame(
            PtwWorkflowService::STAGE_VERIFY,
            app(PtwWorkflowService::class)->pendingStage($permit),
        );
    }

    public function test_submission_notifies_the_configured_verifier(): void
    {
        $this->actingAs($this->requester)->post('/permits-to-work', $this->formPayload())->assertRedirect();

        $notification = $this->notificationsFor($this->verifier)->first();

        $this->assertNotNull($notification, 'The configured verifier must be told a permit is waiting.');
        $this->assertStringContainsString('verification', $notification->title);
        $this->assertSame(PtwWorkflowService::STAGE_VERIFY, $notification->meta['ptw_stage'] ?? null);
        $this->assertStringContainsString('/permits-to-work/', (string) $notification->url);
    }

    /**
     * THE ORDER, WHICH IS THE WHOLE POINT. The approver hears nothing until
     * a verification has actually been recorded.
     */
    public function test_the_approver_is_not_notified_before_verification(): void
    {
        $this->actingAs($this->requester)->post('/permits-to-work', $this->formPayload())->assertRedirect();

        $this->assertCount(0, $this->notificationsFor($this->approver),
            'The approver was told about a permit nobody has verified yet.');
    }

    /*
    |--------------------------------------------------------------------------
    | 3-4. Verification is recorded, and THEN the approver is told
    |--------------------------------------------------------------------------
    */

    public function test_verification_records_the_decision_and_notifies_the_approver(): void
    {
        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $permit->refresh();

        $this->assertSame(PermitToWork::STATUS_VERIFIED, $permit->status);
        $this->assertSame($this->verifier->id, $permit->hse_verifier_id);
        $this->assertNotNull($permit->verified_at);
        $this->assertNull($permit->hse_approver_id, 'Verifying is not approving.');

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => PermitToWork::class,
            'subject_id' => $permit->id,
            'action' => PermitToWork::STATUS_VERIFIED,
            'user_id' => $this->verifier->id,
        ]);

        $notification = $this->notificationsFor($this->approver)->first();
        $this->assertNotNull($notification, 'The approver must be told once verification is recorded.');
        $this->assertSame(PtwWorkflowService::STAGE_APPROVE, $notification->meta['ptw_stage'] ?? null);
    }

    /** A verified permit is still not a permit anybody may work under. */
    public function test_a_verified_permit_is_still_not_active(): void
    {
        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $this->assertSame(PermitToWork::STATUS_VERIFIED, $permit->refresh()->status);

        // And it cannot be jumped straight to active either.
        $this->transition($this->approver, $permit, PermitToWork::STATUS_ACTIVE);
        $this->assertSame(PermitToWork::STATUS_VERIFIED, $permit->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Approval, and only then ACTIVE
    |--------------------------------------------------------------------------
    */

    public function test_approval_records_its_own_decision_and_only_then_may_the_permit_activate(): void
    {
        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit, PermitToWork::STATUS_APPROVED)->assertRedirect();

        $permit->refresh();

        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->status);
        $this->assertSame($this->approver->id, $permit->hse_approver_id);
        $this->assertSame($this->verifier->id, $permit->hse_verifier_id, 'The verification record survives the approval.');
        $this->assertNotNull($permit->approved_at);

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => PermitToWork::class,
            'subject_id' => $permit->id,
            'action' => PermitToWork::STATUS_APPROVED,
            'user_id' => $this->approver->id,
        ]);

        // ACTIVE is reachable now, and was not before.
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_ACTIVE)->assertRedirect();
        $this->assertSame(PermitToWork::STATUS_ACTIVE, $permit->refresh()->status);
    }

    /**
     * AND THE APPROVER CAN ACTIVATE IT THEMSELVES.
     *
     * Activation was `canManageHse()` alone, which became a trap the moment
     * approval moved elsewhere: a company routing final approval to a
     * manager got a permit that was approved and that nobody on the screen
     * could activate. Seen in a browser, where the approver approved the
     * permit and then had no Activate button.
     */
    public function test_the_approver_can_activate_the_permit_they_approved(): void
    {
        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit, PermitToWork::STATUS_APPROVED)->assertRedirect();

        $this->assertFalse($this->approver->canManageHse(), 'The point of the fixture: this approver is not HSE.');

        $this->transition($this->approver, $permit, PermitToWork::STATUS_ACTIVE)->assertRedirect();

        $this->assertSame(PermitToWork::STATUS_ACTIVE, $permit->refresh()->status);
    }

    /** Somebody who holds neither gate still cannot activate. */
    public function test_a_bystander_cannot_activate_an_approved_permit(): void
    {
        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit, PermitToWork::STATUS_APPROVED)->assertRedirect();

        $this->transition($this->bystander, $permit, PermitToWork::STATUS_ACTIVE)->assertForbidden();

        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 6-7. Authority, and the order, enforced on the SERVER
    |--------------------------------------------------------------------------
    */

    /**
     * Hiding a button is not authorization. Every one of these is a direct
     * POST, which is what anybody who can sign in is able to send.
     */
    public function test_a_user_without_authority_cannot_verify_or_approve(): void
    {
        $permit = $this->permit();

        $this->transition($this->bystander, $permit, PermitToWork::STATUS_VERIFIED)->assertForbidden();
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->refresh()->status);

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $this->transition($this->bystander, $permit, PermitToWork::STATUS_APPROVED)->assertForbidden();
        $this->assertSame(PermitToWork::STATUS_VERIFIED, $permit->refresh()->status);
    }

    /**
     * THE TWO AUTHORITIES ARE NOT ONE. A verifier is not an approver, and
     * an approver is not a verifier, unless the company says both.
     */
    public function test_verification_authority_does_not_carry_approval_authority(): void
    {
        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        // The verifier holds stage one and not stage two.
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_APPROVED)->assertForbidden();
        $this->assertSame(PermitToWork::STATUS_VERIFIED, $permit->refresh()->status);

        // And the approver held stage two and not stage one.
        $other = $this->permit();
        $this->transition($this->approver, $other, PermitToWork::STATUS_VERIFIED)->assertForbidden();
        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $other->refresh()->status);
    }

    /** A company may name one person for both, and then they hold both. */
    public function test_a_company_may_deliberately_grant_one_person_both_gates(): void
    {
        $this->route($this->company, verify: [User::ROLE_HSE], approve: [User::ROLE_HSE]);

        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_APPROVED)->assertRedirect();

        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->refresh()->status);
    }

    /**
     * VERIFICATION CANNOT BE SKIPPED, and the refusal is the STATE MACHINE's
     * rather than a permission check -- so no caller anywhere, including a
     * console command or a future controller, can produce an approved
     * permit nobody verified.
     */
    public function test_a_submitted_permit_cannot_be_approved_without_verification(): void
    {
        $permit = $this->permit();

        $this->transition($this->approver, $permit, PermitToWork::STATUS_APPROVED)->assertRedirect();

        $permit->refresh();

        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->status, 'submitted -> approved must be refused.');
        $this->assertNull($permit->hse_approver_id);
        $this->assertNull($permit->approved_at);
    }

    /** And not even the model may be talked into it. */
    public function test_the_state_machine_itself_refuses_the_skip(): void
    {
        $permit = $this->permit();

        $this->assertFalse($permit->canTransitionTo(PermitToWork::STATUS_APPROVED));
        $this->assertTrue($permit->canTransitionTo(PermitToWork::STATUS_VERIFIED));
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Double approval, repeats, and invalid transitions
    |--------------------------------------------------------------------------
    */

    /**
     * A SECOND APPROVAL DOES NOT OVERWRITE THE FIRST.
     *
     * Two approvers pressing Approve on a page rendered minutes apart is
     * ordinary, and without the locked re-read the second write would take
     * over `hse_approver_id` -- so the seal would name whoever lost the
     * race, which is the quiet kind of wrong.
     */
    public function test_a_second_approval_cannot_overwrite_the_first(): void
    {
        $second = $this->user('approver-two', User::ROLE_MANAGER, $this->tenant, $this->company);
        $this->route($this->company, verify: [User::ROLE_HSE], approve: [User::ROLE_MANAGER]);

        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit, PermitToWork::STATUS_APPROVED)->assertRedirect();

        $firstApprover = $permit->refresh()->hse_approver_id;
        $firstMoment = $permit->approved_at;

        $this->transition($second, $permit, PermitToWork::STATUS_APPROVED);

        $permit->refresh();

        $this->assertSame($firstApprover, $permit->hse_approver_id, 'The second press took over the first approval.');
        $this->assertEquals($firstMoment, $permit->approved_at);
        $this->assertSame(
            1,
            ActivityLog::where('subject_type', PermitToWork::class)
                ->where('subject_id', $permit->id)
                ->where('action', PermitToWork::STATUS_APPROVED)
                ->count(),
            'One decision, one audit row.',
        );
    }

    /** Re-sending the same verification does not record it twice. */
    public function test_repeating_a_verification_is_refused_rather_than_duplicated(): void
    {
        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);

        $this->assertSame(
            1,
            ActivityLog::where('subject_type', PermitToWork::class)
                ->where('subject_id', $permit->id)
                ->where('action', PermitToWork::STATUS_VERIFIED)
                ->count(),
        );
    }

    public function test_a_closed_permit_accepts_no_further_decision(): void
    {
        $permit = $this->permit(PermitToWork::STATUS_CLOSED);

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED);
        $this->transition($this->approver, $permit, PermitToWork::STATUS_APPROVED);

        $this->assertSame(PermitToWork::STATUS_CLOSED, $permit->refresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | 9. A notification failure must not cost the decision
    |--------------------------------------------------------------------------
    */

    /**
     * The owner's requirement in as many words: "Kegagalan pengiriman
     * notifikasi tidak membuat keputusan persetujuan hilang."
     *
     * The notifier is replaced with one that throws. The decision must
     * still be committed, the audit row written, and the request still
     * succeed -- because the record IS the decision and the notification is
     * a courtesy on top of it.
     */
    public function test_a_failing_notification_does_not_lose_the_verification(): void
    {
        $this->app->bind(NotificationService::class, fn () => new class extends NotificationService
        {
            public function notify(User $user, string $category, string $title, ?string $body = null, ?string $url = null, ?\Illuminate\Database\Eloquent\Model $subject = null, array $meta = []): ?Notification
            {
                throw new \RuntimeException('mail is down');
            }
        });

        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $permit->refresh();

        $this->assertSame(PermitToWork::STATUS_VERIFIED, $permit->status);
        $this->assertSame($this->verifier->id, $permit->hse_verifier_id);
        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => PermitToWork::class,
            'subject_id' => $permit->id,
            'action' => PermitToWork::STATUS_VERIFIED,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 10. Nothing leaks between companies
    |--------------------------------------------------------------------------
    */

    public function test_another_tenants_officer_cannot_verify_or_approve_this_permit(): void
    {
        [$otherTenant, $otherCompany] = $this->makeTenant('b');
        $theirHse = $this->user('their-hse', User::ROLE_HSE, $otherTenant, $otherCompany);
        $this->route($otherCompany, verify: [User::ROLE_HSE], approve: [User::ROLE_MANAGER]);

        app(CurrentTenant::class)->set($this->tenant->fresh());
        $permit = $this->permit();

        $this->transition($theirHse, $permit, PermitToWork::STATUS_VERIFIED);

        $this->assertSame(PermitToWork::STATUS_SUBMITTED, $permit->refresh()->status,
            'An HSE role exists in every tenant; it must not be a key to every tenant.');
    }

    public function test_one_companys_routing_is_not_another_companys(): void
    {
        [, $otherCompany] = $this->makeTenant('c');
        $this->route($otherCompany, verify: [User::ROLE_HRD], approve: [User::ROLE_HRD]);

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $settings = app(PtwWorkflowService::class)->settingsFor($this->company->id);

        $this->assertSame([User::ROLE_HSE], $settings['verify_roles']);
        $this->assertNotContains(User::ROLE_HRD, $settings['verify_roles']);
    }

    public function test_notifications_reach_only_this_tenants_recipients(): void
    {
        [$otherTenant, $otherCompany] = $this->makeTenant('d');
        $theirManager = $this->user('their-manager', User::ROLE_MANAGER, $otherTenant, $otherCompany);

        app(CurrentTenant::class)->set($this->tenant->fresh());
        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $this->assertCount(0, $this->notificationsFor($theirManager));
        $this->assertNotCount(0, $this->notificationsFor($this->approver));
    }

    /*
    |--------------------------------------------------------------------------
    | 11. Stamps follow the record, and the record only
    |--------------------------------------------------------------------------
    */

    public function test_no_stamp_is_rendered_for_a_stage_that_has_not_happened(): void
    {
        $permit = $this->permit();

        $this->actingAs($this->verifier)
            ->get('/permits-to-work/'.$permit->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification', null)
                ->where('authorization', null)
                ->where('stamps.verification', null)
                ->where('stamps.approval', null));
    }

    public function test_the_verification_stamp_appears_only_after_verification_and_names_its_actor(): void
    {
        $position = Position::withoutGlobalScopes()->create([
            'name' => 'SPV HSE', 'company_id' => $this->company->id,
        ]);
        $employee = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-5001', 'full_name' => 'Sri Handayani',
            'company_id' => $this->company->id, 'position_id' => $position->id, 'status' => 'active',
        ]);
        $this->verifier->forceFill(['employee_id' => $employee->id])->save();

        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $this->actingAs($this->verifier)
            ->get('/permits-to-work/'.$permit->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('verification.role', 'SPV HSE')
                ->whereNot('verification.approver', null)
                ->whereNot('stamps.verification', null)
                // Verified is not approved, and the page must not pretend.
                ->where('authorization', null)
                ->where('stamps.approval', null));
    }

    /**
     * THE TITLE IS A FACT ABOUT THE PAST, for the verification exactly as it
     * already is for the approval (v2.100.0). A company reorganising must
     * not rewrite a permit already filed.
     */
    public function test_renaming_a_position_does_not_rewrite_a_recorded_verification(): void
    {
        $position = Position::withoutGlobalScopes()->create([
            'name' => 'Supervisor HSE', 'company_id' => $this->company->id,
        ]);
        $employee = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-5002', 'full_name' => 'Sri Handayani',
            'company_id' => $this->company->id, 'position_id' => $position->id, 'status' => 'active',
        ]);
        $this->verifier->forceFill(['employee_id' => $employee->id])->save();

        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $position->update(['name' => 'Head of Safety']);

        $this->actingAs($this->verifier)
            ->get('/permits-to-work/'.$permit->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('verification.role', 'Supervisor HSE'));
    }

    /** And the PDF carries both decisions, from the same records. */
    public function test_the_pdf_renders_with_both_decisions_recorded(): void
    {
        $permit = $this->permit();

        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit, PermitToWork::STATUS_APPROVED)->assertRedirect();

        $response = $this->actingAs($this->verifier)->get('/permits-to-work/'.$permit->id.'/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertGreaterThan(3000, strlen($response->getContent()));
    }

    /*
    |--------------------------------------------------------------------------
    | 12. Changing the routing changes who is told NEXT
    |--------------------------------------------------------------------------
    */

    public function test_changing_the_recipients_affects_the_next_notification_not_the_last(): void
    {
        $newApprover = $this->user('new-approver', User::ROLE_MANAGER, $this->tenant, $this->company);

        $first = $this->permit();
        $this->transition($this->verifier, $first, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $this->assertNotCount(0, $this->notificationsFor($this->approver));
        $this->assertCount(0, $this->notificationsFor($newApprover));

        // The company re-routes.
        $this->route($this->company, verify: [User::ROLE_HSE], approve: [], approveUsers: [$newApprover->id]);

        $second = $this->permit();
        $this->transition($this->verifier, $second, PermitToWork::STATUS_VERIFIED)->assertRedirect();

        $this->assertNotCount(0, $this->notificationsFor($newApprover), 'The new approver must be told about the next permit.');
        $this->assertSame(
            1,
            $this->notificationsFor($this->approver)->count(),
            'The previous approver keeps the notification they already had, and gets no new one.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration itself
    |--------------------------------------------------------------------------
    */

    /** A company that has configured nothing keeps working. */
    public function test_an_unconfigured_company_falls_back_rather_than_locking_out(): void
    {
        PtwWorkflowSetting::withoutGlobalScopes()->where('company_id', $this->company->id)->delete();

        $settings = app(PtwWorkflowService::class)->settingsFor($this->company->id);

        $this->assertFalse($settings['configured']);
        $this->assertContains(User::ROLE_HSE, $settings['verify_roles']);
        $this->assertContains(User::ROLE_MANAGER, $settings['approve_roles']);

        $permit = $this->permit();
        $this->transition($this->verifier, $permit, PermitToWork::STATUS_VERIFIED)->assertRedirect();
        $this->transition($this->approver, $permit, PermitToWork::STATUS_APPROVED)->assertRedirect();

        $this->assertSame(PermitToWork::STATUS_APPROVED, $permit->refresh()->status);
    }

    /** Saving ONE stage empty must not silently disable it. */
    public function test_an_empty_stage_falls_back_instead_of_blocking(): void
    {
        $this->route($this->company, verify: [], approve: [User::ROLE_MANAGER]);

        $settings = app(PtwWorkflowService::class)->settingsFor($this->company->id);

        $this->assertContains(User::ROLE_HSE, $settings['verify_roles'],
            'An empty stage must fall back to the default, not mean "nobody".');
    }

    /** A My Work account is never offered a decision it would be refused. */
    public function test_a_my_work_account_is_never_a_workflow_recipient(): void
    {
        $employee = Employee::withoutGlobalScopes()->create([
            'employee_id' => 'EMP-5003', 'full_name' => 'Field Worker',
            'company_id' => $this->company->id, 'status' => 'active',
        ]);

        $field = User::create([
            'name' => 'Field Worker', 'email' => null, 'password' => bcrypt('code'),
            'role' => User::ROLE_HSE, 'user_type' => User::TYPE_MY_WORK,
            'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
        $field->forceFill(['employee_id' => $employee->id])->save();

        $recipients = app(PtwWorkflowService::class)
            ->recipientsFor($this->permit(), PtwWorkflowService::STAGE_VERIFY)
            ->pluck('id');

        $this->assertNotContains($field->id, $recipients,
            'RestrictMyWorkUser refuses that class the pages a decision is made on -- ADR 061.');
        $this->assertContains($this->verifier->id, $recipients);
    }

    /** The page offers exactly what the server would accept, and no more. */
    public function test_the_page_offers_each_gate_only_to_whoever_holds_it(): void
    {
        $permit = $this->permit();

        $this->actingAs($this->verifier)->get('/permits-to-work/'.$permit->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workflow.can_verify', true)
                ->where('workflow.can_approve', false)
                ->where('workflow.pending_stage', PtwWorkflowService::STAGE_VERIFY));

        $this->actingAs($this->approver)->get('/permits-to-work/'.$permit->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workflow.can_verify', false)
                ->where('workflow.can_approve', true));

        $this->actingAs($this->bystander)->get('/permits-to-work/'.$permit->id)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('workflow.can_verify', false)
                ->where('workflow.can_approve', false));
    }

    /** The create form's payload, as the real request sends it. */
    private function formPayload(): array
    {
        return [
            'company_id' => $this->company->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Welding on the main deck.',
            'start_datetime' => '2026-11-01 08:00:00',
            'end_datetime' => '2026-11-01 17:00:00',
        ];
    }
}
