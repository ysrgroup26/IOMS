<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\EntitlementService;
use App\Services\PricingService;
use App\Services\SubscriptionLifecycleService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\Support\ApprovedCatalogue;
use Tests\TestCase;

/**
 * v2.82.0 -- THE APPROVED PRICING MODEL, AND WHAT A USER IS.
 *
 * Two changes that look like one. The catalogue is now three tiers with a
 * SMALL included allowance instead of four with large hard caps -- and the
 * allowance stopped being a ceiling, because exceeding it is a purchase.
 *
 * The risk in that is not the arithmetic. It is the definition: three
 * plausible readings of "user" are all wrong, and each would overcharge or
 * undercharge somebody. A device is not a user (one account, many devices).
 * An employee record is not a user (most people in a yard never log in). A
 * deactivated account is not a user (its slot is free). All three are
 * asserted below, because a pricing model is only as good as the thing it
 * counts.
 *
 * The annual model is the second trap: three different offers on one
 * ladder, only two of which are discounts. Business pays twelve months and
 * receives fourteen, so its benefit lives in the PERIOD and is invisible to
 * anything that only compares prices.
 */
class UserEntitlementAndAddOnTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['saas.enforce_entitlement' => true]);
    }

    /* ==================================================================
     * Fixtures
     * ================================================================== */

    private function plan(string $slug): Package
    {
        return Package::where('slug', $slug)->sole();
    }

    private function subscribe(string $slug, array $overrides = []): Subscription
    {
        $package = $this->plan($slug);

        $this->tenant = Tenant::create([
            'name' => 'Yard '.$slug, 'slug' => 'yard-'.$slug.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YRD', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);
        $this->tenant->workspaces()->sync(Workspace::pluck('id'));

        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
            ...$overrides,
        ]);

        app(CurrentTenant::class)->set($this->tenant);

        $this->admin = User::create([
            'name' => 'Owner', 'email' => 'owner-'.uniqid().'@yard.test', 'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        return $subscription->fresh();
    }

    /** Creates $count extra accounts, active unless told otherwise. */
    private function addUsers(int $count, bool $active = true): void
    {
        for ($i = 0; $i < $count; $i++) {
            User::create([
                'name' => 'Staff '.$i, 'email' => 'staff-'.uniqid().'@yard.test',
                'password' => bcrypt('secret-pass-2'), 'role' => User::ROLE_HSE,
                'tenant_id' => $this->tenant->id, 'is_active' => $active,
            ]);
        }
    }

    private function entitlements(): EntitlementService
    {
        return app(EntitlementService::class);
    }

    /* ==================================================================
     * 1-6. The catalogue
     * ================================================================== */

    #[\PHPUnit\Framework\Attributes\DataProvider('planProvider')]
    public function test_each_plan_carries_its_approved_price_and_allowance(string $slug): void
    {
        [$monthly, $yearly, $included] = ApprovedCatalogue::PLANS[$slug];

        $package = $this->plan($slug);

        $this->assertEquals($monthly, (float) $package->price_monthly, "{$slug} monthly price");
        $this->assertEquals($yearly, (float) $package->price_yearly, "{$slug} annual price");
        $this->assertSame($included, $package->includedUsers(), "{$slug} included active users");
    }

    public static function planProvider(): array
    {
        return array_map(fn ($slug) => [$slug], array_combine(
            array_keys(ApprovedCatalogue::PLANS),
            array_keys(ApprovedCatalogue::PLANS)
        ));
    }

    public function test_the_public_catalogue_is_exactly_the_three_approved_tiers(): void
    {
        $sold = app(PricingService::class)->publicPlans()->pluck('slug')->all();

        $this->assertSame(array_keys(ApprovedCatalogue::PLANS), $sold);

        foreach (ApprovedCatalogue::RETIRED as $slug) {
            $this->assertNotContains($slug, $sold, "{$slug} is retired and must not be sold.");
            // Retired, NOT deleted: tenants are still subscribed to it.
            $this->assertNotNull(Package::where('slug', $slug)->first(), "{$slug} must survive as a row.");
        }
    }

    /* ==================================================================
     * 7. One add-on price, everywhere
     * ================================================================== */

    public function test_an_additional_user_costs_the_same_on_every_plan(): void
    {
        $this->assertEquals(ApprovedCatalogue::ADDITIONAL_USER_PRICE, config('saas.additional_user_price'));

        foreach (app(PricingService::class)->publicPlans() as $plan) {
            $this->assertEquals(
                ApprovedCatalogue::ADDITIONAL_USER_PRICE,
                $plan['additional_user']['amount'],
                "{$plan['slug']} must quote the one add-on price."
            );
        }
    }

    /* ==================================================================
     * 8-10. The annual offer
     * ================================================================== */

    #[\PHPUnit\Framework\Attributes\DataProvider('planProvider')]
    public function test_annual_payment_and_service_period_match_the_approved_offer(string $slug): void
    {
        [$paidMonths, $serviceMonths] = ApprovedCatalogue::ANNUAL[$slug];
        [, $yearly] = ApprovedCatalogue::PLANS[$slug];

        $package = $this->plan($slug);

        // The price is exactly N monthly payments -- never a rounded figure.
        $this->assertEquals(
            (float) $package->price_monthly * $paidMonths,
            $yearly,
            "{$slug} annual price must be {$paidMonths} monthly payments."
        );
        $this->assertSame($serviceMonths, $package->annualMonths(), "{$slug} service period");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('planProvider')]
    public function test_an_annual_renewal_extends_by_the_plans_own_service_period(string $slug): void
    {
        [, $serviceMonths] = ApprovedCatalogue::ANNUAL[$slug];

        $subscription = $this->subscribe($slug, [
            'billing_cycle' => Subscription::CYCLE_YEARLY,
            'ends_at' => now()->addDays(10),
        ]);

        $from = $subscription->ends_at->copy();

        app(SubscriptionLifecycleService::class)->extendPeriod($subscription);

        $this->assertSame(
            $from->copy()->addMonths($serviceMonths)->toDateString(),
            $subscription->fresh()->ends_at->toDateString(),
            "{$slug} must grant {$serviceMonths} months of service for an annual payment."
        );
    }

    /** The Business benefit is service, and it must not also appear as a discount. */
    public function test_business_annual_grants_fourteen_months_for_twelve_payments(): void
    {
        $package = $this->plan('business');

        $this->assertSame(14, $package->annualMonths());
        $this->assertEquals(12.0, $package->annualPaidMonths());
        $this->assertEquals(
            (float) $package->price_monthly * 12,
            (float) $package->price_yearly,
            'Business annual is twelve monthly payments -- there is no price discount to show.'
        );
    }

    /* ==================================================================
     * 11-13. What a user IS
     * ================================================================== */

    public function test_the_active_user_allowance_is_enforced_server_side(): void
    {
        $this->subscribe('starter');          // 3 included, admin is 1 of them
        $this->addUsers(2);                   // now 3 of 3

        $this->assertSame(3, $this->entitlements()->usersUsedCount($this->tenant));
        $this->assertFalse($this->entitlements()->canCreateUser($this->tenant));

        Auth::forgetGuards();

        // The gate is the SERVER's, not the form's.
        $this->actingAs($this->admin)
            ->post(route('settings.users.store'), [
                'name' => 'One Too Many', 'email' => 'extra@yard.test',
                'password' => 'secret-pass-3', 'password_confirmation' => 'secret-pass-3',
                'role' => User::ROLE_HSE,
            ])
            ->assertStatus(422);

        $this->assertSame(3, $this->entitlements()->usersUsedCount($this->tenant));
    }

    public function test_a_deactivated_account_frees_its_slot(): void
    {
        $this->subscribe('starter');
        $this->addUsers(2);
        $this->assertFalse($this->entitlements()->canCreateUser($this->tenant));

        // Deactivating is how capacity is released WITHOUT deleting a
        // person's history -- which a system of record has to allow.
        User::where('tenant_id', $this->tenant->id)
            ->where('id', '!=', $this->admin->id)
            ->first()
            ->update(['is_active' => false]);

        $this->assertSame(2, $this->entitlements()->usersUsedCount($this->tenant));
        $this->assertTrue($this->entitlements()->canCreateUser($this->tenant));
    }

    public function test_an_employee_without_a_login_account_is_not_a_user(): void
    {
        $this->subscribe('starter');

        // Employees and users are separate tables precisely because most
        // people in a yard never sign in.
        $before = $this->entitlements()->usersUsedCount($this->tenant);

        \App\Models\Employee::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => Company::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->value('id'),
            'employee_id' => 'EMP-001',
            'full_name' => 'Welder Without A Login',
            'is_active' => true,
        ]);

        $this->assertSame($before, $this->entitlements()->usersUsedCount($this->tenant));
    }

    public function test_one_account_signing_in_repeatedly_is_still_one_user(): void
    {
        // There are deliberately no device seats in IOMS: capacity is
        // accounts, so signing in again from anywhere changes nothing.
        $this->subscribe('starter');
        $this->addUsers(1);

        $before = $this->entitlements()->usersUsedCount($this->tenant);

        for ($i = 0; $i < 3; $i++) {
            Auth::forgetGuards();
            $this->flushSession();
            $this->actingAs($this->admin)->get(route('work-center.index'))->assertOk();
        }

        $this->assertSame($before, $this->entitlements()->usersUsedCount($this->tenant));
    }

    /* ==================================================================
     * 14-15. Additional users
     * ================================================================== */

    public function test_purchased_capacity_raises_the_limit_and_is_charged_monthly(): void
    {
        $subscription = $this->subscribe('starter', ['additional_users' => 2]);

        $this->assertSame(3, $subscription->includedUsers());
        $this->assertSame(2, $subscription->additionalUsers());
        $this->assertSame(5, $subscription->seatLimit());

        $this->assertEquals(
            2 * ApprovedCatalogue::ADDITIONAL_USER_PRICE,
            $subscription->additionalUserCharge(Subscription::CYCLE_MONTHLY)
        );

        // And the extra accounts are genuinely creatable.
        $this->addUsers(4);
        $this->assertSame(5, $this->entitlements()->usersUsedCount($this->tenant));
        $this->assertFalse($this->entitlements()->canCreateUser($this->tenant));
    }

    /**
     * On an annual cycle the add-on is billed for the months the customer
     * PAYS for, not the months they receive -- one rule for the whole
     * invoice. Billing the service months instead would charge Business
     * MORE for an add-on than the benefit it was given.
     */
    public function test_annual_add_on_is_billed_for_the_months_that_are_paid(): void
    {
        foreach (ApprovedCatalogue::ANNUAL as $slug => [$paidMonths]) {
            $subscription = $this->subscribe($slug, [
                'billing_cycle' => Subscription::CYCLE_YEARLY,
                'additional_users' => 3,
            ]);

            $this->assertEquals(
                3 * ApprovedCatalogue::ADDITIONAL_USER_PRICE * $paidMonths,
                $subscription->additionalUserCharge(Subscription::CYCLE_YEARLY),
                "{$slug} must bill add-ons for the {$paidMonths} months it charges for."
            );
        }
    }

    public function test_a_renewal_invoice_carries_the_plan_and_the_add_on(): void
    {
        $subscription = $this->subscribe('professional', [
            'additional_users' => 4,
            'ends_at' => now()->addDays(3),
            'agreed_price_monthly' => ApprovedCatalogue::PLANS['professional'][0],
            'agreed_price_yearly' => ApprovedCatalogue::PLANS['professional'][1],
            'agreed_currency' => 'IDR',
        ]);

        $invoice = app(SubscriptionLifecycleService::class)->issueRenewalInvoice($subscription, force: true);

        $this->assertNotNull($invoice);
        $this->assertEquals(
            ApprovedCatalogue::PLANS['professional'][0] + (4 * ApprovedCatalogue::ADDITIONAL_USER_PRICE),
            (float) $invoice->amount,
            'A renewal must bill the plan AND the capacity the customer holds.'
        );
    }

    public function test_a_customer_cannot_set_their_own_price(): void
    {
        $this->subscribe('starter');

        Auth::forgetGuards();

        // The browser sends a QUANTITY. Anything else it sends is ignored,
        // and every figure is recomputed server-side.
        $this->actingAs($this->admin)
            ->put(route('subscription.additional-users'), [
                'additional_users' => 2,
                'additional_user_price' => 1,
                'amount' => 1,
            ])
            ->assertRedirect();

        $subscription = $this->tenant->subscription->fresh();

        $this->assertSame(2, $subscription->additionalUsers());
        $this->assertEquals(
            2 * ApprovedCatalogue::ADDITIONAL_USER_PRICE,
            $subscription->additionalUserCharge(Subscription::CYCLE_MONTHLY),
            'The charge must come from configuration, never from the request.'
        );
    }

    public function test_an_ordinary_member_cannot_change_capacity(): void
    {
        $this->subscribe('starter');

        $member = User::create([
            'name' => 'Member', 'email' => 'member@yard.test', 'password' => bcrypt('secret-pass-2'),
            'role' => User::ROLE_HSE, 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Auth::forgetGuards();

        $this->actingAs($member)
            ->put(route('subscription.additional-users'), ['additional_users' => 50])
            ->assertForbidden();

        $this->assertSame(0, $this->tenant->subscription->fresh()->additionalUsers());
    }

    public function test_capacity_cannot_be_released_below_the_accounts_in_use(): void
    {
        $this->subscribe('starter', ['additional_users' => 3]);
        $this->addUsers(5);   // 6 active against 3 + 3

        Auth::forgetGuards();

        $this->actingAs($this->admin)
            ->put(route('subscription.additional-users'), ['additional_users' => 0])
            ->assertRedirect();

        // Refused, and nothing was deactivated on the customer's behalf.
        $this->assertSame(3, $this->tenant->subscription->fresh()->additionalUsers());
        $this->assertSame(6, $this->entitlements()->usersUsedCount($this->tenant));
    }

    /* ==================================================================
     * 16. Plan changes
     * ================================================================== */

    public function test_a_downgrade_is_refused_while_it_would_strand_active_accounts(): void
    {
        $this->subscribe('business');     // 25 included
        $this->addUsers(11);              // 12 active

        Auth::forgetGuards();

        $this->actingAs($this->admin)
            ->post(route('subscription.plan-change'), [
                'package_id' => $this->plan('professional')->id,   // 10 included
                'billing_cycle' => Subscription::CYCLE_MONTHLY,
            ])
            ->assertRedirect();

        // Still on Business, and no account was deactivated to make room.
        $this->assertSame('business', $this->tenant->subscription->fresh()->package->slug);
        $this->assertSame(12, $this->entitlements()->usersUsedCount($this->tenant));
    }

    public function test_purchased_capacity_survives_a_plan_change_and_counts_towards_the_new_plan(): void
    {
        $this->subscribe('business', ['additional_users' => 5]);
        $this->addUsers(13);   // 14 active, against 25 + 5

        Auth::forgetGuards();

        // Professional gives 10 + the same 5 purchased = 15, which covers 14.
        $this->actingAs($this->admin)
            ->post(route('subscription.plan-change'), [
                'package_id' => $this->plan('professional')->id,
                'billing_cycle' => Subscription::CYCLE_MONTHLY,
            ])
            ->assertRedirect();

        $subscription = $this->tenant->subscription->fresh();

        $this->assertSame(5, $subscription->additionalUsers(), 'Purchased capacity belongs to the customer, not the plan.');
    }

    /* ==================================================================
     * 17-19. One source of truth, and history
     * ================================================================== */

    public function test_the_customer_and_the_operator_read_the_same_capacity(): void
    {
        $this->subscribe('professional', ['additional_users' => 3]);
        $this->addUsers(4);

        $operator = User::withoutGlobalScopes()->create([
            'name' => 'Ops', 'email' => 'ops-cap@ioms.test', 'password' => bcrypt('secret-pass-9'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);

        Auth::forgetGuards();
        $this->flushSession();
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $customer = $this->actingAs($this->admin->fresh())
            ->get(route('subscription.billing'))
            ->assertOk()
            ->viewData('page')['props'];

        Auth::forgetGuards();
        $this->flushSession();

        $platform = $this->actingAs($operator)
            ->get(route('platform.tenants.show', $this->tenant->id))
            ->assertOk()
            ->viewData('page')['props']['subscription'];

        // The shared snapshot is the reason these cannot drift.
        $this->assertSame($platform['included_users'], $customer['entitlements']['users']['included']);
        $this->assertSame($platform['additional_users'], $customer['entitlements']['users']['additional']);
        $this->assertSame($platform['seat_limit'], $customer['entitlements']['users']['limit']);
        $this->assertSame($platform['active_users'], $customer['entitlements']['users']['used']);
    }

    public function test_repricing_the_catalogue_does_not_reprice_an_existing_customer(): void
    {
        // The v2.60.0 snapshot columns exist for exactly this, and the
        // v2.82.0 repricing must not have quietly bypassed them.
        $subscription = $this->subscribe('business', [
            'agreed_price_monthly' => 1499000,
            'agreed_price_yearly' => 14990000,
            'agreed_currency' => 'IDR',
        ]);

        $this->assertEquals(1499000.0, $subscription->agreedAmountFor(Subscription::CYCLE_MONTHLY));
        $this->assertTrue($subscription->isOnLegacyPricing());
    }

    public function test_a_paid_invoice_is_never_rewritten_by_a_price_change(): void
    {
        $subscription = $this->subscribe('starter');

        $invoice = Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $subscription->id,
            'invoice_number' => 'INV-HIST-0001',
            'purpose' => Invoice::PURPOSE_RENEWAL,
            'amount' => 299000, 'currency' => 'IDR',
            'status' => Invoice::STATUS_PAID,
            'payment_date' => now()->subMonth(),
        ]);

        $this->plan('starter')->update(['price_monthly' => 189000]);

        $this->assertEquals(299000.0, (float) $invoice->fresh()->amount, 'History is a record, not a projection.');
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /* ==================================================================
     * 18. Lifecycle parity is untouched
     * ================================================================== */

    public function test_the_lifecycle_states_still_behave_as_before(): void
    {
        $subscription = $this->subscribe('starter', ['additional_users' => 2]);

        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->lifecycleState());

        $subscription->update(['ends_at' => now()->subDays(3)]);
        $this->assertSame(Subscription::LIFECYCLE_GRACE, $subscription->fresh()->lifecycleState());
        $this->assertTrue($subscription->fresh()->allowsWrites());

        $subscription->update(['ends_at' => now()->subDays(10)]);
        $this->assertSame(Subscription::LIFECYCLE_LAPSED, $subscription->fresh()->lifecycleState());
        $this->assertFalse($subscription->fresh()->allowsWrites());
        $this->assertTrue($subscription->fresh()->allowsReads());

        $subscription->update(['status' => Subscription::STATUS_SUSPENDED, 'ends_at' => now()->addYear()]);
        $this->assertSame(Subscription::LIFECYCLE_SUSPENDED, $subscription->fresh()->lifecycleState());

        // And capacity survived every one of those transitions.
        $this->assertSame(2, $subscription->fresh()->additionalUsers());
    }
}
