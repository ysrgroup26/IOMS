<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\SubscriptionAddOn;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AddOnPurchaseService;
use App\Services\SubscriptionLifecycleService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.104.0 -- THE FOUR RULES AN ADD-ON IS BOUGHT AND RENEWED BY.
 *
 * Extra capacity used to be a number on the subscription: the quantity went
 * live at once and the charge appeared on the NEXT renewal invoice. That
 * meant capacity was used before it was paid for, and everything landed on
 * the subscription's anniversary, so a customer who bought the day after
 * renewing waited a year to be billed.
 *
 * The approved model replaces it with a purchase:
 *
 *   PAID BEFORE GRANTED   an invoice is raised and nothing counts until a
 *                         verified payment settles it
 *   ITS OWN CLOCK         the period starts at payment and renews on its
 *                         own date, never the subscription's
 *   NO PRORATION          a period is bought whole, in either direction
 *   EARLY PAYMENT IS SAFE paying a renewal early extends from the existing
 *                         end date, so the anniversary never drifts and the
 *                         same invoice never charges twice
 *
 * The capacity already granted under the earlier arrangement is deliberately
 * left alone and still counts: re-selling it would bill a customer twice for
 * what they already have.
 */
class AddOnBillingFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Subscription $subscription;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        $package = Package::where('slug', 'professional')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Galangan Co', 'code' => 'GC', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        $this->subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE,
            'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonths(6),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'is_active' => true,
        ]);
    }

    private function service(): AddOnPurchaseService
    {
        return app(AddOnPurchaseService::class);
    }

    /** Settles an invoice the way the webhook does, through the one entry point. */
    private function settle(Invoice $invoice): void
    {
        $invoice->markPaid('TEST-'.$invoice->id, 'test');
        app(SubscriptionLifecycleService::class)->applyPaidInvoice($invoice->fresh());
    }

    /* ==================================================================
     * 1. PAID BEFORE IT IS GRANTED
     * ================================================================== */

    public function test_buying_capacity_raises_an_invoice_and_grants_nothing(): void
    {
        $before = $this->subscription->seatLimit();

        $invoice = $this->service()->purchase(
            $this->subscription,
            SubscriptionAddOn::TYPE_ADDITIONAL_USERS,
            3
        );

        $this->assertSame(Invoice::PURPOSE_ADD_ON, $invoice->purpose);
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);

        $addOn = SubscriptionAddOn::sole();
        $this->assertSame(SubscriptionAddOn::STATUS_PENDING_PAYMENT, $addOn->status);
        $this->assertNull($addOn->starts_at);
        $this->assertFalse($addOn->isCounted());

        $this->assertSame(
            $before,
            $this->subscription->fresh()->seatLimit(),
            'Capacity must not be granted before the invoice is paid.'
        );
    }

    public function test_the_price_comes_from_configuration_and_is_snapshotted(): void
    {
        $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 3);

        $addOn = SubscriptionAddOn::sole();
        $expected = (float) config('saas.additional_user_price');

        $this->assertSame($expected, (float) $addOn->unit_price);
        $this->assertSame($expected * 3, (float) $addOn->invoices()->sole()->amount);

        // A later catalogue change does not reprice what was already bought.
        config(['saas.additional_user_price' => $expected * 2]);

        $this->assertSame($expected * 3, $addOn->fresh()->periodCharge());
    }

    public function test_paying_the_invoice_activates_the_capacity(): void
    {
        $before = $this->subscription->seatLimit();

        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 3);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();

        $this->assertSame(SubscriptionAddOn::STATUS_ACTIVE, $addOn->status);
        $this->assertNotNull($addOn->starts_at);
        $this->assertTrue($addOn->isCounted());

        $this->assertSame(
            $before + 3,
            $this->subscription->fresh()->seatLimit(),
            'Paid capacity must count.'
        );
    }

    /* ==================================================================
     * 2. ITS OWN CLOCK
     * ================================================================== */

    public function test_the_add_on_period_is_independent_of_the_subscription(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();

        $this->assertTrue(
            $addOn->ends_at->isSameDay(now()->addMonth()),
            'A monthly add-on runs a month from payment, not to the subscription end.'
        );

        $this->assertTrue(
            $this->subscription->fresh()->ends_at->isSameDay(now()->addMonths(6)),
            'Buying an add-on must not move the subscription.'
        );
    }

    public function test_two_add_ons_bought_apart_fall_due_apart(): void
    {
        // v2.131.0: pinned to a date whose month and the next have the same
        // length. Run on 25 October the test failed with 9 != 10: +1 month
        // from 25 Oct and from 4 Nov straddles a 30-day November, and the
        // product was right -- each add-on did end a month after ITS OWN
        // payment. A calendar-dependent assertion is not a regression.
        $this->travelTo(now()->setDate(2027, 1, 5)->startOfDay());

        $first = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($first);

        $this->travel(10)->days();

        $second = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_MY_WORK_PACKS, 1);
        $this->settle($second);

        $ends = SubscriptionAddOn::orderBy('id')->pluck('ends_at');

        $this->assertSame(
            10,
            (int) $ends[0]->diffInDays($ends[1]),
            'Each add-on renews on its own anniversary.'
        );
    }

    /* ==================================================================
     * 3. EARLY PAYMENT MUST NOT MOVE THE SCHEDULE
     * ================================================================== */

    public function test_paying_a_renewal_early_extends_from_the_existing_end_date(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();
        $originalEnd = $addOn->ends_at->copy();

        // A renewal invoice, paid a week before the period runs out.
        $this->travelTo($originalEnd->copy()->subDays(7));
        $renewal = $this->service()->raiseRenewalInvoice($addOn->fresh());
        $this->settle($renewal);

        $this->assertTrue(
            $addOn->fresh()->ends_at->isSameDay($originalEnd->copy()->addMonth()),
            'Paying early must buy the next period, not restart the clock from today.'
        );
    }

    public function test_the_same_renewal_invoice_never_charges_twice(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();
        $renewal = $this->service()->raiseRenewalInvoice($addOn->fresh());

        $this->settle($renewal);
        $afterFirst = $addOn->fresh()->ends_at->copy();

        // A provider that delivers the same notification twice, which they
        // all do. The invoice is already paid, so nothing is applied again.
        app(SubscriptionLifecycleService::class)->applyPaidInvoice($renewal->fresh());

        $this->assertTrue(
            $addOn->fresh()->ends_at->equalTo($afterFirst),
            'Applying a settled invoice twice must extend the period once.'
        );
    }

    public function test_an_add_on_paid_after_it_lapsed_starts_a_fresh_period(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();
        $renewal = $this->service()->raiseRenewalInvoice($addOn->fresh());

        // Paid two months after it lapsed. Selling time that has already
        // passed would charge for capacity nobody could use.
        $this->travelTo($addOn->ends_at->copy()->addMonths(2));
        $this->settle($renewal);

        $this->assertTrue(
            $addOn->fresh()->ends_at->isSameDay(now()->addMonth()),
            'A lapsed add-on renews from today, not from a date in the past.'
        );
    }

    /* ==================================================================
     * 4. CANCELLATION PRESERVES THE PAID PERIOD
     * ================================================================== */

    public function test_cancelling_keeps_the_capacity_until_the_period_ends(): void
    {
        $before = $this->subscription->seatLimit();

        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 2);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();
        $endsAt = $addOn->ends_at->copy();

        $this->service()->cancel($addOn);

        $addOn->refresh();

        $this->assertSame(SubscriptionAddOn::STATUS_CANCELLED, $addOn->status);
        $this->assertNotNull($addOn->cancelled_at);
        $this->assertTrue($addOn->ends_at->equalTo($endsAt), 'Cancelling must not shorten the paid period.');
        $this->assertTrue($addOn->isCounted(), 'The capacity is paid for and must keep counting.');

        $this->assertSame(
            $before + 2,
            $this->subscription->fresh()->seatLimit(),
            'A customer must keep what they paid for until it runs out.'
        );
    }

    public function test_cancelled_capacity_stops_counting_once_the_period_ends(): void
    {
        $before = $this->subscription->seatLimit();

        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 2);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();
        $this->service()->cancel($addOn);

        $this->travelTo($addOn->fresh()->ends_at->copy()->addDay());

        $this->assertFalse($addOn->fresh()->isCounted());
        $this->assertSame($before, $this->subscription->fresh()->seatLimit());
    }

    public function test_a_cancelled_add_on_is_not_renewed(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();
        $this->service()->cancel($addOn);

        $this->travelTo($addOn->fresh()->ends_at->copy()->subDay());

        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $this->assertSame(
            1,
            Invoice::where('subscription_add_on_id', $addOn->id)->count(),
            'A cancelled add-on must not be billed again.'
        );
    }

    /** Nothing is deleted, so the billing history stays complete. */
    public function test_cancelling_deletes_nothing(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);

        $this->service()->cancel(SubscriptionAddOn::sole());

        $this->assertDatabaseHas('subscription_add_ons', ['id' => SubscriptionAddOn::sole()->id]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => Invoice::STATUS_PAID]);
    }

    /* ==================================================================
     * RENEWAL RUNS ON THE ADD-ON'S OWN DATE
     * ================================================================== */

    public function test_the_daily_job_bills_an_add_on_when_its_own_date_approaches(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();

        // Well inside the add-on's period: nothing is due yet.
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);
        $this->assertSame(1, Invoice::where('subscription_add_on_id', $addOn->id)->count());

        $this->travelTo($addOn->ends_at->copy()->subDay());
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $this->assertSame(
            2,
            Invoice::where('subscription_add_on_id', $addOn->id)->count(),
            'The renewal invoice is raised as the add-on approaches its own end date.'
        );
    }

    public function test_an_unpaid_renewal_is_not_billed_again_every_night(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);

        $addOn = SubscriptionAddOn::sole();
        $this->travelTo($addOn->ends_at->copy()->subDay());

        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);
        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $this->assertSame(
            2,
            Invoice::where('subscription_add_on_id', $addOn->id)->count(),
            'A customer who has not paid yet must not be sent a second bill each night.'
        );
    }

    /* ==================================================================
     * CAPACITY GRANTED UNDER THE EARLIER ARRANGEMENT IS LEFT ALONE
     * ================================================================== */

    public function test_existing_granted_capacity_still_counts_and_is_not_re_sold(): void
    {
        $this->subscription->forceFill(['additional_users' => 5])->save();

        $included = $this->subscription->fresh()->includedUsers();

        $this->assertSame($included + 5, $this->subscription->fresh()->seatLimit());

        // Buying more adds to it rather than replacing it.
        $invoice = $this->service()->purchase($this->subscription->fresh(), SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 2);
        $this->settle($invoice);

        $this->assertSame(
            $included + 7,
            $this->subscription->fresh()->seatLimit(),
            'Capacity already granted must not be lost or billed again when more is bought.'
        );
    }

    /* ==================================================================
     * THE ENDPOINTS
     * ================================================================== */

    public function test_an_administrator_buys_capacity_through_the_endpoint(): void
    {
        $this->actingAs($this->admin)
            ->post(route('subscription.add-ons.store'), [
                'type' => SubscriptionAddOn::TYPE_ADDITIONAL_USERS,
                'quantity' => 2,
            ])
            ->assertRedirect(route('subscription.billing'));

        $this->assertSame(SubscriptionAddOn::STATUS_PENDING_PAYMENT, SubscriptionAddOn::sole()->status);
    }

    public function test_the_browser_cannot_set_the_price(): void
    {
        $this->actingAs($this->admin)
            ->post(route('subscription.add-ons.store'), [
                'type' => SubscriptionAddOn::TYPE_ADDITIONAL_USERS,
                'quantity' => 2,
                'unit_price' => 1,
                'amount' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(
            (float) config('saas.additional_user_price') * 2,
            (float) Invoice::where('purpose', Invoice::PURPOSE_ADD_ON)->sole()->amount,
            'The amount is computed on the server, whatever the form sends.'
        );
    }

    public function test_an_ordinary_member_cannot_buy_or_cancel_capacity(): void
    {
        $member = User::create([
            'name' => 'Member', 'email' => 'm-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => 'hse',
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'is_active' => true,
        ]);

        $this->actingAs($member)
            ->post(route('subscription.add-ons.store'), [
                'type' => SubscriptionAddOn::TYPE_ADDITIONAL_USERS,
                'quantity' => 1,
            ])
            ->assertForbidden();

        $this->assertSame(0, SubscriptionAddOn::count());
    }

    /* ==================================================================
     * THE OLD DEFERRED PATH NO LONGER SELLS CAPACITY
     * ================================================================== */

    /**
     * Raising the number used to grant capacity at once and bill it at the
     * next renewal. That is the deferred billing the approved model
     * removed, so the increase is refused and pointed at the purchase.
     */
    public function test_capacity_can_no_longer_be_granted_without_payment(): void
    {
        $before = $this->subscription->seatLimit();

        $this->actingAs($this->admin)
            ->put(route('subscription.additional-users'), ['additional_users' => 5])
            ->assertSessionHas('error');

        $this->assertSame(
            $before,
            $this->subscription->fresh()->seatLimit(),
            'No capacity may be granted without a settled payment.'
        );
    }

    /** Giving capacity back costs nobody anything and stays immediate. */
    public function test_capacity_can_still_be_released(): void
    {
        $this->subscription->forceFill(['additional_users' => 4])->save();
        $included = $this->subscription->fresh()->includedUsers();

        $this->actingAs($this->admin)
            ->put(route('subscription.additional-users'), ['additional_users' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame($included + 1, $this->subscription->fresh()->seatLimit());
    }

    /* ==================================================================
     * THE BILLING CENTRE
     * ================================================================== */

    public function test_the_billing_centre_totals_everything_still_owed(): void
    {
        $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 2);
        $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_MY_WORK_PACKS, 1);

        $expected = (float) Invoice::whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_OVERDUE])->sum('amount');

        $this->actingAs($this->admin)
            ->get(route('subscription.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('billingCentre.count', 2)
                ->where('billingCentre.total', fn ($total) => (float) $total === $expected));
    }

    /** A paid invoice leaves the outstanding list. */
    public function test_a_settled_invoice_is_no_longer_outstanding(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);

        $this->actingAs($this->admin)
            ->get(route('subscription.billing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('billingCentre.count', 0));
    }

    /** Pay All Outstanding starts with the oldest, which is the one at risk. */
    public function test_pay_all_outstanding_starts_with_the_oldest_invoice(): void
    {
        $first = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);

        $this->travel(5)->days();
        $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_MY_WORK_PACKS, 1);

        $this->actingAs($this->admin)
            ->post(route('subscription.pay-outstanding'), [])
            ->assertRedirect(route('subscription.pay', $first->id));
    }

    public function test_pay_selected_starts_with_the_oldest_of_the_chosen_invoices(): void
    {
        $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);

        $this->travel(5)->days();
        $second = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_MY_WORK_PACKS, 1);

        $this->actingAs($this->admin)
            ->post(route('subscription.pay-outstanding'), ['invoice_ids' => [$second->id]])
            ->assertRedirect(route('subscription.pay', $second->id));
    }

    /** Another customer's invoice is not payable from here, whatever is posted. */
    public function test_pay_outstanding_never_reaches_another_tenants_invoice(): void
    {
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $otherSubscription = Subscription::create([
            'tenant_id' => $otherTenant->id,
            'package_id' => Package::where('slug', 'professional')->sole()->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonths(6),
        ]);
        $theirs = $this->service()->purchase($otherSubscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);

        $this->actingAs($this->admin)
            ->post(route('subscription.pay-outstanding'), ['invoice_ids' => [$theirs->id]])
            ->assertRedirect(route('subscription.billing'));

        $this->assertSame(Invoice::STATUS_ISSUED, $theirs->fresh()->status);
    }

    /** An add-on id in a URL must never reach another customer's capacity. */
    public function test_an_add_on_cannot_be_cancelled_across_tenants(): void
    {
        $invoice = $this->service()->purchase($this->subscription, SubscriptionAddOn::TYPE_ADDITIONAL_USERS, 1);
        $this->settle($invoice);
        $mine = SubscriptionAddOn::sole();

        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'o-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'OC', 'code' => 'OC', 'tenant_id' => $otherTenant->id, 'is_active' => true,
        ]);
        Subscription::create([
            'tenant_id' => $otherTenant->id,
            'package_id' => Package::where('slug', 'professional')->sole()->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonths(6),
        ]);
        $intruder = User::create([
            'name' => 'Intruder', 'email' => 'i-'.uniqid().'@o.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $otherTenant->id, 'company_id' => $otherCompany->id, 'is_active' => true,
        ]);

        app(CurrentTenant::class)->set($otherTenant->fresh());

        $this->actingAs($intruder)
            ->delete(route('subscription.add-ons.cancel', $mine->id))
            ->assertNotFound();

        $this->assertSame(SubscriptionAddOn::STATUS_ACTIVE, $mine->fresh()->status);
    }
}
