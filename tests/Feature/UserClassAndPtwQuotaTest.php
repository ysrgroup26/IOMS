<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\PtwQuotaConsumption;
use App\Models\PtwQuotaGrant;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Company;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use App\Services\EntitlementService;
use App\Services\PtwQuotaService;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.86.0 -- the approved monetization model, asserted.
 *
 * The figures here are transcribed from the approved decision on purpose,
 * not read from `config/plans.php`, for the same reason `ApprovedCatalogue`
 * is: a test that derives its expectation from the code under test asserts
 * only that the code equals itself.
 *
 * The most important test in this file is not any of the quota arithmetic.
 * It is `a_my_work_user_cannot_reach_what_a_full_user_pays_for`. A My Work
 * User is sold at a fifth of a Full User's price, so an unenforced
 * restriction is a five-to-one arbitrage rather than a cosmetic bug, and
 * only a server-side check proves it. It is attempted by DIRECT URL, which
 * is the same method the v2.84.0 plan matrix suite uses.
 */
class UserClassAndPtwQuotaTest extends TestCase
{
    use RefreshDatabase;

    /** slug => [full users, my work users, included ptw per month] */
    private const APPROVED = [
        'starter' => [3, 10, 50],
        'professional' => [10, 30, 200],
        'business' => [25, 50, 500],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['saas.enforce_entitlement' => true]);
    }

    /**
     * Follows the fixture shape UserEntitlementAndAddOnTest already uses:
     * the plan catalogue arrives from the pricing migrations rather than a
     * seeder, and a tenant needs a Company and its workspaces before any
     * authenticated route will serve it.
     */
    private function tenantOn(string $slug, string $cycle = 'monthly'): Tenant
    {
        $package = Package::where('slug', $slug)->sole();

        $tenant = Tenant::create([
            'name' => 'Yard '.$slug,
            'slug' => 'yard-'.$slug.'-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YRD', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $tenant->workspaces()->sync(Workspace::pluck('id'));

        Subscription::create([
            'tenant_id' => $tenant->id,
            'package_id' => $package->id,
            'type' => 'subscription',
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => $cycle,
            'starts_at' => now()->subDays(3),
            'ends_at' => $cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
        ]);

        app(CurrentTenant::class)->set($tenant);

        return $tenant->fresh('subscription');
    }

    private function userOn(Tenant $tenant, string $type = User::TYPE_FULL, array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Staff '.uniqid(),
            'email' => 'staff-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_HSE,
            'tenant_id' => $tenant->id,
            'user_type' => $type,
            'is_active' => true,
        ], $attributes));
    }

    /* ---------------------------------------------------------------- */
    /* Catalogue                                                        */
    /* ---------------------------------------------------------------- */

    public function test_every_plan_carries_the_approved_capacities(): void
    {

        foreach (self::APPROVED as $slug => [$full, $myWork, $ptw]) {
            $package = Package::where('slug', $slug)->firstOrFail();

            $this->assertSame($full, (int) $package->max_users, "{$slug} Full Users");
            $this->assertSame($myWork, (int) $package->max_my_work_users, "{$slug} My Work Users");
            $this->assertSame($ptw, (int) $package->ptw_included_monthly, "{$slug} included PTW");
        }
    }

    public function test_the_config_mirror_agrees_with_the_packages_table(): void
    {

        foreach (self::APPROVED as $slug => [, $myWork, $ptw]) {
            $this->assertSame($myWork, (int) config("plans.included_my_work_users.{$slug}"));
            $this->assertSame($ptw, (int) config("plans.ptw_included_monthly.{$slug}"));
        }
    }

    public function test_enterprise_keeps_no_stated_ceiling(): void
    {

        $enterprise = Package::where('slug', 'enterprise')->first();

        if (! $enterprise) {
            $this->markTestSkipped('Enterprise is not present in this installation.');
        }

        // Giving a retired plan a number here would silently downgrade a
        // live customer who bought a plan with no stated limit.
        $this->assertNull($enterprise->max_my_work_users);
        $this->assertNull($enterprise->ptw_included_monthly);
    }

    /* ---------------------------------------------------------------- */
    /* User classes                                                     */
    /* ---------------------------------------------------------------- */

    public function test_existing_accounts_are_full_users_after_migration(): void
    {
        $tenant = $this->tenantOn('starter');
        $user = $this->userOn($tenant);

        $this->assertTrue($user->isFullUser());
        $this->assertFalse($user->isMyWorkUser());
    }

    public function test_a_my_work_user_does_not_consume_full_user_capacity(): void
    {
        $tenant = $this->tenantOn('starter');
        $entitlements = app(EntitlementService::class);

        $this->userOn($tenant);
        $this->userOn($tenant);
        $this->userOn($tenant);

        // Starter allows 3 Full Users. All three are used.
        $this->assertSame(3, $entitlements->usersUsedCount($tenant));
        $this->assertFalse($entitlements->canCreateUser($tenant));

        // Ten My Work users must not change that figure at all.
        for ($i = 0; $i < 10; $i++) {
            $this->userOn($tenant, User::TYPE_MY_WORK);
        }

        $tenant->refresh();

        $this->assertSame(3, $entitlements->usersUsedCount($tenant), 'My Work users leaked into the Full User count.');
        $this->assertSame(10, $entitlements->myWorkUsersUsedCount($tenant));
    }

    public function test_the_my_work_allowance_is_enforced_server_side(): void
    {
        $tenant = $this->tenantOn('starter');
        $entitlements = app(EntitlementService::class);

        $this->assertSame(10, $entitlements->myWorkSeatLimit($tenant));

        for ($i = 0; $i < 10; $i++) {
            $this->userOn($tenant, User::TYPE_MY_WORK);
        }

        $tenant->refresh();

        $this->assertSame(0, $entitlements->remainingMyWorkSlots($tenant));
        $this->assertFalse($entitlements->canCreateUserOfType($tenant, User::TYPE_MY_WORK));
        // The Full pool is untouched by the My Work pool being full.
        $this->assertTrue($entitlements->canCreateUserOfType($tenant, User::TYPE_FULL));
    }

    public function test_purchased_my_work_packs_raise_the_limit_by_ten_each(): void
    {
        $tenant = $this->tenantOn('starter');
        $tenant->subscription->update(['additional_my_work_packs' => 2]);
        $tenant->refresh();

        // 10 included + 2 packs of 10.
        $this->assertSame(30, app(EntitlementService::class)->myWorkSeatLimit($tenant->fresh('subscription')));
    }

    public function test_a_deactivated_account_frees_its_own_class(): void
    {
        $tenant = $this->tenantOn('starter');
        $user = $this->userOn($tenant, User::TYPE_MY_WORK);

        $this->assertSame(1, app(EntitlementService::class)->myWorkUsersUsedCount($tenant));

        $user->update(['is_active' => false]);

        $this->assertSame(0, app(EntitlementService::class)->myWorkUsersUsedCount($tenant->fresh()));
    }

    /**
     * THE ONE THAT MATTERS. A cheaper class that is not actually restricted
     * is a discount on the full product, so this is attempted by direct URL
     * rather than by checking that a menu item is hidden.
     */
    public function test_a_my_work_user_cannot_reach_what_a_full_user_pays_for(): void
    {
        $tenant = $this->tenantOn('business');
        $myWork = $this->userOn($tenant, User::TYPE_MY_WORK, ['role' => 'admin']);

        // Deliberately given the strongest tenant role available, to prove
        // the restriction is on the CLASS and cannot be escaped with a role.
        foreach ([
            '/employees',
            '/settings',
            '/subscription/billing',
            '/admin',
            '/management',
            '/items',
            '/warehouses',
            '/kpi-records',
        ] as $url) {
            $response = $this->actingAs($myWork)->get($url);

            // The property that matters is that the page was NOT SERVED.
            // Which flavour of refusal arrives is an implementation detail
            // of the route -- 403 from the class restriction, 405 where the
            // path is write-only, 404 where a binding fails -- and pinning
            // the code would make this test fail on changes that do not
            // weaken anything.
            $this->assertNotSame(
                200,
                $response->getStatusCode(),
                "A My Work user was served {$url}."
            );
        }
    }

    /**
     * v2.89.0 -- THE CLASS DECIDES WHERE A MY WORK USER LANDS, NOT THE
     * PREFERENCE.
     *
     * `landingRouteName()` branched on `is_field_user` alone, which was right
     * while that flag was the only distinction. A My Work User whose landing
     * preference happened to be off was therefore sent to `dashboard`: a
     * route the class allow-list permits, in a workspace the account cannot
     * actually use.
     *
     * `is_field_user` keeps its exact meaning for a Full User, which is the
     * only account the preference was ever about.
     */
    public function test_a_my_work_user_lands_in_my_work_whatever_its_preference_says(): void
    {
        $tenant = $this->tenantOn('business');

        $withPreference = $this->userOn($tenant, User::TYPE_MY_WORK, ['is_field_user' => true]);
        $withoutPreference = $this->userOn($tenant, User::TYPE_MY_WORK, ['is_field_user' => false]);

        $this->assertSame('my-work', $withPreference->landingRouteName());
        $this->assertSame('my-work', $withoutPreference->landingRouteName());

        // The preference still decides for a Full User, unchanged.
        $fieldFull = $this->userOn($tenant, User::TYPE_FULL, ['is_field_user' => true]);
        $officeFull = $this->userOn($tenant, User::TYPE_FULL, ['is_field_user' => false]);

        $this->assertSame('my-work', $fieldFull->landingRouteName());
        $this->assertSame('dashboard', $officeFull->landingRouteName());
    }

    /**
     * A My Work deep link authenticates first and routes afterwards.
     *
     * This is what an emailed My Work invitation is: the URL itself. It must
     * never be a way past the sign-in form.
     */
    public function test_a_my_work_deep_link_requires_authentication_and_then_routes_through(): void
    {
        $tenant = $this->tenantOn('business');
        $user = $this->userOn($tenant, User::TYPE_MY_WORK);

        // Unauthenticated, the deep link is captured rather than served.
        $this->get('/my-work')->assertRedirect('/login');

        // And signing in replays it.
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-1'])
            ->assertRedirect('/my-work');
    }

    public function test_a_my_work_user_can_still_reach_my_work(): void
    {
        $tenant = $this->tenantOn('business');
        $myWork = $this->userOn($tenant, User::TYPE_MY_WORK, ['is_field_user' => true]);

        $this->actingAs($myWork)->get('/my-work')->assertOk();
    }

    /**
     * The control for the test above.
     *
     * It asserts that the CLASS restriction is what refused the My Work
     * user, rather than something the route would refuse anybody. A Full
     * User may still be turned away from these pages by a policy or a role
     * gate -- that is unrelated and unchanged -- so this checks the one
     * thing that must differ: the middleware's own refusal is not raised.
     */
    public function test_the_restriction_applies_to_the_class_and_not_to_full_users(): void
    {
        $tenant = $this->tenantOn('business');
        $full = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);
        $myWork = $this->userOn($tenant, User::TYPE_MY_WORK, ['role' => 'super_admin']);

        // Both are on EnforceTenantEntitlement's allow-list, so a tenant
        // administrator reaches them regardless of workspace grants. That
        // makes them the clean comparison: the only thing that can differ
        // between these two accounts is the class.
        foreach (['/settings', '/subscription/billing'] as $url) {
            $this->actingAs($myWork)->get($url)->assertForbidden();
            $this->actingAs($full)->get($url)->assertOk();
        }
    }

    public function test_my_work_class_does_not_grant_ptw_creation(): void
    {
        $tenant = $this->tenantOn('starter');
        $myWork = $this->userOn($tenant, User::TYPE_MY_WORK, ['role' => 'user', 'ptw_access' => false]);

        // The approved model: a My Work User does NOT automatically receive
        // PTW creation. It stays an explicit capability decision.
        $this->assertFalse($myWork->canCreatePtw());

        $myWork->update(['ptw_access' => true]);

        $this->assertTrue($myWork->fresh()->canCreatePtw());
    }

    /* ---------------------------------------------------------------- */
    /* PTW quota                                                        */
    /* ---------------------------------------------------------------- */

    public function test_a_tenant_receives_its_monthly_included_allowance(): void
    {
        $tenant = $this->tenantOn('professional');
        $quota = app(PtwQuotaService::class);

        $balance = $quota->balance($tenant);

        $this->assertTrue($balance['metered']);
        $this->assertSame(200, $balance['included']);
        $this->assertSame(0, $balance['purchased']);
        $this->assertSame(200, $balance['total']);
    }

    public function test_included_quota_is_consumed_before_purchased(): void
    {
        $tenant = $this->tenantOn('starter');
        $quota = app(PtwQuotaService::class);

        $quota->creditPurchased($tenant, 150);

        // 50 included + 150 purchased.
        $this->assertSame(200, $quota->remaining($tenant));

        // Spend 70. The approved example: included 50 -> 0, purchased
        // 150 -> 130.
        for ($i = 0; $i < 70; $i++) {
            $quota->consume($tenant, $this->permitFor($tenant));
        }

        $this->assertSame(0, $quota->remainingIn($tenant, PtwQuotaService::KIND_INCLUDED));
        $this->assertSame(130, $quota->remainingIn($tenant, PtwQuotaService::KIND_PURCHASED));
    }

    public function test_included_quota_expires_and_purchased_quota_carries_forward(): void
    {
        $tenant = $this->tenantOn('starter');
        $quota = app(PtwQuotaService::class);

        $quota->creditPurchased($tenant, 130);
        $quota->balance($tenant);

        // Spend 40 of the 50 included, leaving 10 unused.
        for ($i = 0; $i < 40; $i++) {
            $quota->consume($tenant, $this->permitFor($tenant));
        }

        $this->assertSame(10, $quota->remainingIn($tenant, PtwQuotaService::KIND_INCLUDED));

        // Cross the monthly boundary.
        $this->travel(32)->days();

        $balance = $quota->balance($tenant->fresh('subscription'));

        // The 10 expired, a fresh 50 arrived, and the purchased 130 is
        // untouched by the rollover.
        $this->assertSame(50, $balance['included'], 'Unused included quota carried forward, which it must not.');
        $this->assertSame(130, $balance['purchased'], 'Purchased quota was reset by a renewal, which it must not be.');
    }

    public function test_creation_is_locked_when_both_pools_are_empty(): void
    {
        $tenant = $this->tenantOn('starter');
        $quota = app(PtwQuotaService::class);

        for ($i = 0; $i < 50; $i++) {
            $quota->consume($tenant, $this->permitFor($tenant));
        }

        $this->assertSame(0, $quota->remaining($tenant));
        $this->assertFalse($quota->canCreate($tenant));

        $quota->creditPurchased($tenant, 50);

        $this->assertTrue($quota->canCreate($tenant));
    }

    public function test_deleting_a_permit_does_not_refund_its_document(): void
    {
        $tenant = $this->tenantOn('starter');
        $quota = app(PtwQuotaService::class);

        $permit = $this->permitFor($tenant);
        $quota->consume($tenant, $permit);

        $this->assertSame(49, $quota->remaining($tenant));

        $permit->delete();

        // The create-delete-repeat loophole, closed.
        $this->assertSame(49, $quota->remaining($tenant->fresh()), 'Deleting a permit restored quota.');
        $this->assertDatabaseCount('ptw_quota_consumptions', 1);
    }

    public function test_a_permit_consumes_exactly_one_document_however_often_it_is_charged(): void
    {
        $tenant = $this->tenantOn('starter');
        $quota = app(PtwQuotaService::class);
        $permit = $this->permitFor($tenant);

        $quota->consume($tenant, $permit);
        $quota->consume($tenant, $permit);
        $quota->consume($tenant, $permit);

        $this->assertSame(49, $quota->remaining($tenant));
        $this->assertDatabaseCount('ptw_quota_consumptions', 1);
    }

    public function test_an_annual_plan_receives_monthly_quota_not_a_lump_sum(): void
    {
        $tenant = $this->tenantOn('business', 'yearly');
        $quota = app(PtwQuotaService::class);

        // 500 for the month, NOT 6000 for the year.
        $this->assertSame(500, $quota->balance($tenant)['included']);
    }

    public function test_an_annual_term_receives_twelve_allocations_not_fourteen(): void
    {
        $tenant = $this->tenantOn('business', 'yearly');
        $quota = app(PtwQuotaService::class);

        // Walk the term month by month, granting each window as it arrives.
        for ($month = 0; $month < 14; $month++) {
            $quota->ensureIncludedGrant($tenant->fresh('subscription'), now()->addMonths($month));
        }

        // Business annual buys 14 months of ACCESS and 12 of PTW
        // entitlement. Months 13 and 14 mint nothing.
        $this->assertSame(12, PtwQuotaGrant::where('tenant_id', $tenant->id)
            ->where('kind', PtwQuotaService::KIND_INCLUDED)->count());
    }

    public function test_granting_the_same_window_twice_is_a_no_op(): void
    {
        $tenant = $this->tenantOn('starter');
        $quota = app(PtwQuotaService::class);

        $quota->ensureIncludedGrant($tenant);
        $quota->ensureIncludedGrant($tenant);
        $quota->ensureIncludedGrant($tenant);

        $this->assertSame(1, PtwQuotaGrant::where('tenant_id', $tenant->id)
            ->where('kind', PtwQuotaService::KIND_INCLUDED)->count());
        $this->assertSame(50, $quota->remaining($tenant));
    }

    public function test_a_plan_without_a_ptw_figure_is_not_metered(): void
    {
        $tenant = $this->tenantOn('starter');
        $tenant->subscription->package->update(['ptw_included_monthly' => null]);
        $tenant = $tenant->fresh('subscription.package');

        $quota = app(PtwQuotaService::class);

        // Unmetered, not zero. A misconfigured plan must not stop a safety
        // permit being raised.
        $this->assertFalse($quota->isMetered($tenant));
        $this->assertTrue($quota->canCreate($tenant));
        $this->assertNull($quota->remaining($tenant));
        $this->assertFalse($quota->balance($tenant)['metered']);
    }

    public function test_a_low_balance_is_flagged(): void
    {
        $tenant = $this->tenantOn('starter');
        $quota = app(PtwQuotaService::class);

        $this->assertFalse($quota->balance($tenant)['low']);

        // Down to 5 of 50, which is under the 20% threshold.
        for ($i = 0; $i < 45; $i++) {
            $quota->consume($tenant, $this->permitFor($tenant));
        }

        $this->assertTrue($quota->balance($tenant)['low']);
    }

    /* ---------------------------------------------------------------- */
    /* Purchasing                                                       */
    /* ---------------------------------------------------------------- */

    public function test_top_up_packs_are_priced_from_configuration(): void
    {
        $packs = collect(app(PtwQuotaService::class)->topUpPacks())->keyBy('documents');

        $this->assertSame(50000.0, $packs[50]['price']);
        $this->assertSame(120000.0, $packs[150]['price']);
        $this->assertSame(300000.0, $packs[500]['price']);
    }

    public function test_a_customer_cannot_set_their_own_top_up_price(): void
    {
        $tenant = $this->tenantOn('starter');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);

        $this->actingAs($admin)
            ->post('/permits-to-work/quota/purchase', ['pack' => '500', 'amount' => 1])
            ->assertRedirect();

        $invoice = Invoice::where('tenant_id', $tenant->id)
            ->where('purpose', Invoice::PURPOSE_TOPUP)->firstOrFail();

        // The submitted amount is ignored; the price comes from config.
        $this->assertSame('300000.00', (string) $invoice->amount);
    }

    public function test_an_unknown_pack_buys_nothing(): void
    {
        $tenant = $this->tenantOn('starter');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);

        $this->actingAs($admin)
            ->post('/permits-to-work/quota/purchase', ['pack' => '99999'])
            ->assertRedirect();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_raising_a_top_up_invoice_grants_nothing_until_it_is_paid(): void
    {
        $tenant = $this->tenantOn('starter');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);
        $quota = app(PtwQuotaService::class);

        $this->actingAs($admin)->post('/permits-to-work/quota/purchase', ['pack' => '150']);

        // Reaching a page grants nothing. Only a verified payment does.
        $this->assertSame(0, $quota->remainingIn($tenant, PtwQuotaService::KIND_PURCHASED));

        $invoice = Invoice::where('purpose', Invoice::PURPOSE_TOPUP)->firstOrFail();
        app(SubscriptionLifecycleService::class)->applyPaidInvoice($invoice);

        $this->assertSame(150, $quota->remainingIn($tenant->fresh(), PtwQuotaService::KIND_PURCHASED));
    }

    public function test_a_replayed_payment_notification_credits_once(): void
    {
        $tenant = $this->tenantOn('starter');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);
        $quota = app(PtwQuotaService::class);

        $this->actingAs($admin)->post('/permits-to-work/quota/purchase', ['pack' => '50']);
        $invoice = Invoice::where('purpose', Invoice::PURPOSE_TOPUP)->firstOrFail();

        $lifecycle = app(SubscriptionLifecycleService::class);
        $lifecycle->applyPaidInvoice($invoice);
        $lifecycle->applyPaidInvoice($invoice);
        $lifecycle->applyPaidInvoice($invoice);

        $this->assertSame(50, $quota->remainingIn($tenant->fresh(), PtwQuotaService::KIND_PURCHASED));
    }

    public function test_a_top_up_does_not_extend_the_subscription_period(): void
    {
        $tenant = $this->tenantOn('starter');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);

        $before = $tenant->subscription->ends_at->toDateTimeString();

        $this->actingAs($admin)->post('/permits-to-work/quota/purchase', ['pack' => '50']);
        $invoice = Invoice::where('purpose', Invoice::PURPOSE_TOPUP)->firstOrFail();
        app(SubscriptionLifecycleService::class)->applyPaidInvoice($invoice);

        // Buying documents must not buy time.
        $this->assertSame($before, $tenant->fresh('subscription')->subscription->ends_at->toDateTimeString());
    }

    public function test_an_ordinary_member_cannot_buy_quota(): void
    {
        $tenant = $this->tenantOn('starter');
        $member = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'user']);

        $this->actingAs($member)
            ->post('/permits-to-work/quota/purchase', ['pack' => '50'])
            ->assertForbidden();
    }

    /* ---------------------------------------------------------------- */
    /* The quota page                                                   */
    /* ---------------------------------------------------------------- */

    public function test_anyone_who_may_raise_a_permit_can_read_the_quota(): void
    {
        $tenant = $this->tenantOn('starter');
        // An HSE account may raise a permit, and is not an administrator.
        $hse = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'hse']);

        $this->assertTrue($hse->canCreatePtw());

        $this->actingAs($hse)->get(route('permits-to-work.quota'))
            ->assertOk()
            // Being refused at the moment of writing a permit with no way to
            // find out why is the failure this page exists to prevent, so
            // reading it is deliberately not administrator-only.
            ->assertInertia(fn ($page) => $page
                ->component('Ptw/Quota')
                ->where('balance.metered', true)
                ->where('balance.included', 50)
                ->where('canPurchase', false));
    }

    public function test_an_administrator_sees_the_purchase_controls(): void
    {
        $tenant = $this->tenantOn('starter');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);

        $this->actingAs($admin)->get(route('permits-to-work.quota'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canPurchase', true));
    }

    public function test_an_unmetered_plan_renders_as_unmetered_rather_than_zero(): void
    {
        $tenant = $this->tenantOn('starter');
        $tenant->subscription->package->update(['ptw_included_monthly' => null]);
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);

        $this->actingAs($admin)->get(route('permits-to-work.quota'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('balance.metered', false));
    }

    public function test_the_quota_page_survives_a_deleted_permit(): void
    {
        $tenant = $this->tenantOn('starter');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);
        $quota = app(PtwQuotaService::class);

        $permit = $this->permitFor($tenant);
        $quota->consume($tenant, $permit);
        $permit->delete();

        // The consumption outlives the permit deliberately, so the page has
        // to render a row whose permit is gone.
        $this->actingAs($admin)->get(route('permits-to-work.quota'))->assertOk();
    }

    /* ---------------------------------------------------------------- */
    /* Downgrade safety                                                 */
    /* ---------------------------------------------------------------- */

    public function test_a_downgrade_is_refused_while_it_would_strand_my_work_accounts(): void
    {
        $tenant = $this->tenantOn('business');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);

        // Business allows 50 My Work users; Starter allows 10.
        for ($i = 0; $i < 20; $i++) {
            $this->userOn($tenant, User::TYPE_MY_WORK);
        }

        $starter = Package::where('slug', 'starter')->sole();

        $response = $this->actingAs($admin)
            ->post(route('subscription.plan-change'), ['package_id' => $starter->id, 'billing_cycle' => 'monthly']);

        $response->assertSessionHasErrors('package_id');

        $this->assertStringContainsString(
            'My Work',
            (string) session('errors')->first('package_id'),
            'The downgrade was refused, but not for the My Work capacity reason.'
        );
    }

    public function test_purchased_my_work_capacity_counts_towards_the_target_plan(): void
    {
        $tenant = $this->tenantOn('business');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);

        // Starter allows 10, plus 2 purchased packs = 30. Twenty fits.
        $tenant->subscription->update(['additional_my_work_packs' => 2]);

        for ($i = 0; $i < 20; $i++) {
            $this->userOn($tenant, User::TYPE_MY_WORK);
        }

        $starter = Package::where('slug', 'starter')->sole();

        $this->actingAs($admin)
            ->post(route('subscription.plan-change'), ['package_id' => $starter->id, 'billing_cycle' => 'monthly'])
            ->assertSessionHasNoErrors();
    }

    public function test_creating_a_my_work_account_checks_the_my_work_pool(): void
    {
        $tenant = $this->tenantOn('starter');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);

        // Starter's Full pool is 3, and the admin is one of them. Creating
        // My Work accounts must not be refused by the Full pool being near
        // its limit.
        $this->userOn($tenant);
        $this->userOn($tenant);

        $this->actingAs($admin)->post(route('settings.users.store'), [
            'name' => 'Field Crew',
            'email' => 'crew-'.uniqid().'@yard.test',
            'password' => 'secret-pass-1',
            'role' => 'hse',
            'user_type' => User::TYPE_MY_WORK,
        ])->assertRedirect();

        $this->assertSame(1, app(EntitlementService::class)->myWorkUsersUsedCount($tenant->fresh()));
        // The Full pool is untouched by that creation.
        $this->assertSame(3, app(EntitlementService::class)->usersUsedCount($tenant->fresh()));
    }

    public function test_a_user_created_without_a_class_is_a_full_user(): void
    {
        $tenant = $this->tenantOn('business');
        $admin = $this->userOn($tenant, User::TYPE_FULL, ['role' => 'super_admin']);

        $this->actingAs($admin)->post(route('settings.users.store'), [
            'name' => 'Office Staff',
            'email' => 'staff-'.uniqid().'@yard.test',
            'password' => 'secret-pass-1',
            'role' => 'hse',
        ])->assertRedirect();

        $created = User::where('name', 'Office Staff')->sole();

        // A My Work User must never be produced by an omitted field.
        $this->assertTrue($created->isFullUser());
    }

    /* ---------------------------------------------------------------- */
    /* Invoicing                                                        */
    /* ---------------------------------------------------------------- */

    public function test_a_renewal_invoice_itemises_its_total(): void
    {
        $tenant = $this->tenantOn('business');
        $tenant->subscription->update([
            'additional_users' => 2,
            'additional_my_work_packs' => 3,
        ]);

        $subscription = $tenant->fresh('subscription')->subscription;
        $invoice = app(SubscriptionLifecycleService::class)->issueRenewalInvoice($subscription, force: true);

        $items = $invoice->items()->get()->keyBy('kind');

        $this->assertArrayHasKey(InvoiceItem::KIND_SUBSCRIPTION, $items->all());
        $this->assertArrayHasKey(InvoiceItem::KIND_ADDITIONAL_USERS, $items->all());
        $this->assertArrayHasKey(InvoiceItem::KIND_MY_WORK_PACKS, $items->all());

        // 2 users at Rp50.000 and 3 packs at Rp100.000.
        $this->assertSame(100000.0, (float) $items[InvoiceItem::KIND_ADDITIONAL_USERS]->amount);
        $this->assertSame(300000.0, (float) $items[InvoiceItem::KIND_MY_WORK_PACKS]->amount);

        // The itemisation is real accounting: it sums to the total charged.
        $this->assertSame(
            (float) $invoice->amount,
            (float) $invoice->items()->sum('amount'),
            'Invoice items do not sum to the invoice total.'
        );
    }

    public function test_an_invoice_with_no_add_ons_is_a_single_line(): void
    {
        $tenant = $this->tenantOn('starter');
        $invoice = app(SubscriptionLifecycleService::class)->issueRenewalInvoice($tenant->subscription, force: true);

        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame((float) $invoice->amount, (float) $invoice->items()->sum('amount'));
    }

    /**
     * A permit for a tenant, created without going through the controller.
     *
     * Deliberately minimal and not a realistic permit: these tests are about
     * the meter, and every field beyond what the schema requires would be
     * noise that breaks when the permit form changes.
     */
    private function permitFor(Tenant $tenant): PermitToWork
    {
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $requester = User::where('tenant_id', $tenant->id)->first()
            ?? $this->userOn($tenant);

        return PermitToWork::withoutEvents(fn () => PermitToWork::create([
            'ptw_number' => 'PTW-TEST-'.str()->random(10),
            'company_id' => $company->id,
            'requested_by' => $requester->id,
            'permit_type' => PermitToWork::TYPES[0] ?? 'hot_work',
            'work_description' => 'Test permit',
            'location' => 'Dock 2',
            'start_datetime' => now(),
            'end_datetime' => now()->addDay(),
            'status' => PermitToWork::STATUS_DRAFT,
        ]));
    }
}
