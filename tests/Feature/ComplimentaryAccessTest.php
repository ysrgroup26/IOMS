<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Services\EntitlementService;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * v2.93.0 -- GRANTING ACCESS WITHOUT PAYMENT.
 *
 * `billing_mode = complimentary` existed since v2.80.0, so IOMS could
 * DESCRIBE a free account. It could not CREATE one: a verified
 * organization that was never going to pay could only become a tenant by
 * paying, or by an operator re-typing the whole company identity into the
 * Create Tenant form -- which duplicates an identity the registration
 * already holds and leaves the real registration unprovisioned beside the
 * tenant it became.
 *
 * The danger in closing that gap is not that it fails. It is that it
 * succeeds too much. A "grant access" action is one small step away from
 * three serious defects, and each of them is asserted against here:
 *
 *   1. FAKING A PAYMENT. Marking the invoice paid would make the tenant
 *      work and would corrupt revenue reporting permanently. Complimentary
 *      is the ABSENCE of billing, not a payment with a different label.
 *   2. BECOMING A SECOND ENTITLEMENT SYSTEM. If free access outlived its
 *      dates, `billing_mode` would be a second answer to "may this
 *      customer write today" -- the exact thing ADR 041 forbids and the
 *      lifecycle design exists to keep single.
 *   3. DUPLICATING THE ORGANIZATION. A grant that built a second tenant,
 *      company or Super Admin for a registration would split one customer
 *      in two, and the identity half is what nobody notices until the
 *      invoices go to the wrong row.
 *
 * The scenario throughout is the real one this was built for: a verified
 * registration, granted Starter complimentary for a fixed period, then
 * later converting to paid Professional.
 */
class ComplimentaryAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // Workspaces and modules must exist for a plan's grants to be
        // RECORDED. EntitlementService fails open on a tenant with no
        // grant rows at all, so without these the entitlement assertions
        // below would pass for the wrong reason.
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);

        config(['saas.enforce_entitlement' => true]);
    }

    /* ================================================================== */

    private function package(string $slug, string $name): Package
    {
        return Package::firstOrCreate(['slug' => $slug], [
            'name' => $name,
            'price_monthly' => 500000, 'price_yearly' => 5000000, 'currency' => 'IDR',
            'max_users' => 3, 'max_companies' => 1, 'is_active' => true, 'is_public' => true,
        ]);
    }

    private function operator(): User
    {
        return User::create([
            'name' => 'Master Admin', 'email' => 'ops-'.uniqid().'@iomsuite.test',
            'password' => Hash::make('secret-pass-1'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);
    }

    /**
     * A VERIFIED registration with no tenant -- the state an organization
     * is in after it has confirmed its address and chosen a plan but
     * before it has paid. This is exactly what SubscribeController writes.
     */
    private function verifiedRegistration(array $overrides = []): TenantRegistration
    {
        return TenantRegistration::create(array_merge([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_VERIFIED,
            'contact_name' => 'Operations Lead',
            'contact_email' => 'lead-'.uniqid().'@galangan.test',
            'password' => Hash::make('tenant-pass-1'),
            'company_legal_name' => 'PT. Galangan Aliran Jaya',
            'company_display_name' => 'Galangan Aliran Jaya',
            'company_city' => 'Batam',
            'company_country' => 'Indonesia',
            'package_id' => $this->package('starter', 'Starter')->id,
            'billing_cycle' => 'monthly',
            'amount' => 500000,
            'currency' => 'IDR',
            'email_verified_at' => now(),
            'expires_at' => now()->addDays(7),
        ], $overrides));
    }

    private function grant(User $operator, TenantRegistration $registration, array $payload = [])
    {
        return $this->actingAs($operator)->post(
            route('platform.registrations.complimentary', $registration->id),
            array_merge([
                'package_id' => $this->package('starter', 'Starter')->id,
                'months' => 3,
                'reason' => 'Internal product evaluation',
            ], $payload),
        );
    }

    /* ==================================================================
     * AUTHORIZATION -- who may give away access
     * ================================================================== */

    public function test_platform_admin_can_grant_complimentary_access(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration)->assertSessionHasNoErrors();

        $this->assertNotNull($registration->fresh()->tenant_id);
    }

    /**
     * A tenant's OWN Super Admin is the most dangerous caller here: they
     * are a legitimate, fully authenticated administrator of their own
     * organization, and if the role gate were missing they could grant
     * themselves a free subscription.
     */
    public function test_tenant_super_admin_cannot_grant_complimentary_access(): void
    {
        $tenant = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $customerAdmin = User::create([
            'name' => 'Customer Admin', 'email' => 'cust-'.uniqid().'@x.test', 'password' => Hash::make('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN, 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $registration = $this->verifiedRegistration();

        $this->grant($customerAdmin, $registration)->assertForbidden();

        $this->assertNull($registration->fresh()->tenant_id);
    }

    public function test_guest_cannot_grant_complimentary_access(): void
    {
        $registration = $this->verifiedRegistration();

        $this->post(route('platform.registrations.complimentary', $registration->id), [
            'package_id' => $this->package('starter', 'Starter')->id,
            'months' => 3,
            'reason' => 'Internal product evaluation',
        ])->assertRedirect(route('login'));

        $this->assertNull($registration->fresh()->tenant_id);
    }

    /* ==================================================================
     * THE RESULTING STATE
     * ================================================================== */

    public function test_grant_produces_an_active_complimentary_subscription_with_a_real_end_date(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration, ['months' => 3]);

        $subscription = Tenant::find($registration->fresh()->tenant_id)->subscription;

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame(Subscription::BILLING_MODE_COMPLIMENTARY, $subscription->billing_mode);
        $this->assertTrue($subscription->isComplimentary());
        $this->assertFalse($subscription->isBillable());

        // An ORDINARY subscription, not a trial and not a lifetime licence.
        // Both of those would mean something else commercially, and
        // `lifetime` in particular would mean "never expires".
        $this->assertSame(Subscription::TYPE_SUBSCRIPTION, $subscription->type);
        $this->assertFalse($subscription->isLifetime());

        $this->assertNotNull($subscription->ends_at);
        $this->assertSame(now()->addMonths(3)->toDateString(), $subscription->ends_at->toDateString());

        // Nothing was agreed, so no agreed price is recorded. Null already
        // means "follow the catalogue", which is the right behaviour if
        // they later convert to paying.
        $this->assertNull($subscription->agreed_price_monthly);
        $this->assertNull($subscription->agreed_price_yearly);
    }

    public function test_grant_records_the_reason_and_the_operator(): void
    {
        $operator = $this->operator();
        $registration = $this->verifiedRegistration();

        $this->grant($operator, $registration, ['reason' => 'Internal product evaluation']);

        $subscription = Tenant::find($registration->fresh()->tenant_id)->subscription;

        $this->assertSame('Internal product evaluation', $subscription->notes);
        $this->assertSame($operator->id, $subscription->created_by);
    }

    /* ==================================================================
     * NO PAYMENT, ANYWHERE
     * ================================================================== */

    /**
     * The defect this guards against is the tempting shortcut: make the
     * tenant work by marking its invoice paid. That would corrupt revenue
     * reporting permanently and is indistinguishable afterwards from money
     * that actually arrived.
     */
    public function test_grant_creates_no_payment_and_marks_no_invoice_paid(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration);

        $this->assertSame(0, PaymentTransaction::count());
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('status', Invoice::STATUS_PAID)->count());
        $this->assertNull($registration->fresh()->paid_at);
        $this->assertNotSame(TenantRegistration::STATUS_PAID, $registration->fresh()->status);
    }

    /** No invoice is CONJURED either. A free account has nothing to bill. */
    public function test_grant_raises_no_new_invoice(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration);

        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    /**
     * An order that reached checkout already has an unpaid invoice. It must
     * not be left open -- a free account carrying a live demand for money
     * is what the lifecycle job would chase -- and it must not be marked
     * paid. Voided is the honest third answer.
     */
    public function test_an_unpaid_invoice_is_voided_rather_than_paid(): void
    {
        $invoice = Invoice::withoutGlobalScopes()->create([
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'amount' => 500000, 'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED,
            'purpose' => Invoice::PURPOSE_ONBOARDING,
        ]);

        $registration = $this->verifiedRegistration([
            'status' => TenantRegistration::STATUS_AWAITING_PAYMENT,
            'invoice_id' => $invoice->id,
        ]);

        $this->grant($this->operator(), $registration);

        $invoice->refresh();

        $this->assertSame(Invoice::STATUS_VOID, $invoice->status);
        $this->assertNull($invoice->payment_date);
        $this->assertNull($invoice->payment_reference);
    }

    /* ==================================================================
     * ENTITLEMENTS COME FROM THE PLAN
     * ================================================================== */

    public function test_a_complimentary_starter_tenant_receives_exactly_starter_entitlements(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration, [
            'package_id' => $this->package('starter', 'Starter')->id,
        ]);

        $tenant = Tenant::find($registration->fresh()->tenant_id);
        $granted = app(EntitlementService::class)->grantedWorkspaceKeys($tenant);

        $this->assertContains('hse', $granted);

        // The plans Starter does NOT include. Asserted explicitly because
        // the entitlement service fails OPEN for a tenant with no grant
        // rows at all -- "hse is present" would be true of a tenant that
        // was granted everything by accident.
        $this->assertNotContains('hr', $granted);
        $this->assertNotContains('logistics', $granted);
        $this->assertNotContains('management', $granted);
    }

    public function test_the_granted_plan_is_the_one_chosen_not_the_one_ordered(): void
    {
        $registration = $this->verifiedRegistration([
            'package_id' => $this->package('business', 'Business')->id,
        ]);

        // The operator grants STARTER even though the order was for
        // Business. The plan comes from the operator's decision, re-read
        // server-side, not from whatever the prospect put in their basket.
        $this->grant($this->operator(), $registration, [
            'package_id' => $this->package('starter', 'Starter')->id,
        ]);

        $tenant = Tenant::find($registration->fresh()->tenant_id);

        $this->assertSame('starter', $tenant->subscription->package->slug);
        $this->assertNotContains('hr', app(EntitlementService::class)->grantedWorkspaceKeys($tenant));
    }

    /* ==================================================================
     * EXPIRY -- free access is not unlimited access
     * ================================================================== */

    /**
     * The heart of ADR 041. If a complimentary subscription kept full
     * access past its end date, `billing_mode` would have become a second
     * source of truth for "may this customer write today".
     */
    public function test_an_expired_complimentary_subscription_lapses_to_read_only(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration, ['months' => 1]);

        $subscription = Tenant::find($registration->fresh()->tenant_id)->subscription;

        $this->assertSame(Subscription::LIFECYCLE_ACTIVE, $subscription->lifecycleState());
        $this->assertTrue($subscription->allowsWrites());

        // Travel past the period AND past the grace window.
        $this->travelTo(now()->addMonths(1)->addDays(Subscription::graceDays() + 1));

        $subscription->refresh();

        $this->assertTrue($subscription->isComplimentary(), 'Still complimentary.');
        $this->assertSame(Subscription::LIFECYCLE_LAPSED, $subscription->lifecycleState());
        $this->assertFalse($subscription->allowsWrites(), 'A lapsed free account must not keep write access.');

        // Read-only, never a lockout -- the data is still theirs.
        $this->assertTrue($subscription->allowsReads());
    }

    public function test_the_nightly_lifecycle_job_does_not_invoice_a_complimentary_tenant(): void
    {
        $registration = $this->verifiedRegistration();
        $this->grant($this->operator(), $registration, ['months' => 1]);

        // Into the window where a renewal invoice would be raised.
        $this->travelTo(now()->addMonths(1)->subDays(1));

        $this->artisan('subscriptions:lifecycle')->assertExitCode(0);

        $this->assertSame(0, Invoice::withoutGlobalScopes()->count(), 'A free account must never be invoiced.');
    }

    /* ==================================================================
     * ONE ORGANIZATION, NOT TWO
     * ================================================================== */

    public function test_a_grant_creates_exactly_one_tenant_company_and_administrator(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration);

        $tenantId = $registration->fresh()->tenant_id;

        $this->assertSame(1, Tenant::where('id', $tenantId)->count());
        $this->assertSame(1, Company::withoutGlobalScopes()->where('tenant_id', $tenantId)->count());
        $this->assertSame(1, Subscription::where('tenant_id', $tenantId)->count());
        $this->assertSame(1, User::where('tenant_id', $tenantId)->where('role', User::ROLE_SUPER_ADMIN)->count());

        // Built from the identity the registration already carried, not
        // re-typed by an operator.
        $tenant = Tenant::find($tenantId);
        $this->assertSame('Galangan Aliran Jaya', $tenant->name);
        $this->assertSame(
            $registration->contact_email,
            User::where('tenant_id', $tenantId)->where('role', User::ROLE_SUPER_ADMIN)->value('email'),
        );
    }

    /** Pressing the button twice must not build a second organization. */
    public function test_granting_twice_does_not_duplicate_the_tenant(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration)->assertSessionHasNoErrors();

        $firstTenantId = $registration->fresh()->tenant_id;
        $tenantsAfterFirst = Tenant::count();

        $this->grant($this->operator(), $registration)->assertSessionHasErrors('registration');

        $this->assertSame($tenantsAfterFirst, Tenant::count());
        $this->assertSame($firstTenantId, $registration->fresh()->tenant_id);
        $this->assertSame(1, Subscription::where('tenant_id', $firstTenantId)->count());
    }

    /* ==================================================================
     * WHAT IT REFUSES
     * ================================================================== */

    public function test_an_unverified_registration_cannot_be_granted(): void
    {
        $registration = $this->verifiedRegistration([
            'status' => TenantRegistration::STATUS_PENDING_VERIFICATION,
            'email_verified_at' => null,
        ]);

        $this->grant($this->operator(), $registration)->assertSessionHasErrors('registration');

        $this->assertNull($registration->fresh()->tenant_id);
    }

    /**
     * A registration that PAID goes through ordinary provisioning.
     * Granting it complimentary would discard a real payment.
     */
    public function test_a_paid_registration_cannot_be_granted_complimentary(): void
    {
        $registration = $this->verifiedRegistration([
            'status' => TenantRegistration::STATUS_PAID,
            'paid_at' => now(),
        ]);

        $this->grant($this->operator(), $registration)->assertSessionHasErrors('registration');

        $this->assertNull($registration->fresh()->tenant_id);
    }

    /* ==================================================================
     * THE REQUEST DECIDES NOTHING COMMERCIAL
     * ================================================================== */

    /** A duration outside the configured allow-list is rejected outright. */
    public function test_an_arbitrary_duration_is_rejected(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration, ['months' => 999])
            ->assertSessionHasErrors('months');

        $this->assertNull($registration->fresh()->tenant_id);
    }

    public function test_a_reason_is_required(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration, ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->grant($this->operator(), $registration, ['reason' => 'test'])
            ->assertSessionHasErrors('reason');

        $this->assertNull($registration->fresh()->tenant_id);
    }

    /** An operator must not be able to assign a plan retired from the catalogue. */
    public function test_an_inactive_plan_cannot_be_granted(): void
    {
        $retired = Package::create([
            'name' => 'Enterprise', 'slug' => 'enterprise-retired-'.uniqid(),
            'price_monthly' => 2499000, 'price_yearly' => 24990000, 'currency' => 'IDR',
            'is_active' => false, 'is_public' => false,
        ]);

        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration, ['package_id' => $retired->id])
            ->assertSessionHasErrors('package_id');

        $this->assertNull($registration->fresh()->tenant_id);
    }

    /**
     * The request carries three fields. Anything else it sends about the
     * commercial arrangement is ignored, because the server never reads it.
     */
    public function test_the_request_cannot_dictate_billing_mode_or_dates(): void
    {
        $registration = $this->verifiedRegistration();

        $this->grant($this->operator(), $registration, [
            'months' => 1,
            'billing_mode' => Subscription::BILLING_MODE_PAID,
            'status' => Subscription::STATUS_ACTIVE,
            'type' => Subscription::TYPE_LIFETIME,
            'ends_at' => now()->addYears(50)->toDateString(),
            'tenant_id' => 9999,
        ]);

        $subscription = Tenant::find($registration->fresh()->tenant_id)->subscription;

        $this->assertSame(Subscription::BILLING_MODE_COMPLIMENTARY, $subscription->billing_mode);
        $this->assertSame(Subscription::TYPE_SUBSCRIPTION, $subscription->type);
        $this->assertSame(now()->addMonth()->toDateString(), $subscription->ends_at->toDateString());
    }

    /* ==================================================================
     * AUDIT
     * ================================================================== */

    public function test_the_grant_is_recorded_in_the_activity_log_with_who_why_and_for_how_long(): void
    {
        $operator = $this->operator();
        $registration = $this->verifiedRegistration();

        $this->grant($operator, $registration, [
            'months' => 3,
            'reason' => 'Internal product evaluation',
        ]);

        $entry = ActivityLog::withoutGlobalScopes()
            ->where('description', 'like', '%Complimentary access granted%')
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'Giving away access must leave an audit record.');

        foreach ([
            'Galangan Aliran Jaya',          // which organization
            $registration->reference,         // which registration
            $operator->email,                 // who granted it
            'Starter',                        // which plan
            '3 months',                       // for how long
            now()->toDateString(),            // from when
            now()->addMonths(3)->toDateString(), // until when
            'Internal product evaluation',    // why
        ] as $fact) {
            $this->assertStringContainsString($fact, $entry->description);
        }
    }

    /* ==================================================================
     * CONVERTING TO PAID LATER
     * ================================================================== */

    /**
     * The requirement behind this: the evaluation tenant must be able to
     * become a paying Professional customer later WITHOUT being recreated.
     * The identity half is what matters -- same tenant id, same users,
     * same company, same operational data.
     */
    public function test_a_complimentary_tenant_can_later_become_a_paying_tenant_without_being_recreated(): void
    {
        $operator = $this->operator();
        $registration = $this->verifiedRegistration();

        $this->grant($operator, $registration, [
            'package_id' => $this->package('starter', 'Starter')->id,
        ]);

        $tenantId = $registration->fresh()->tenant_id;
        $subscriptionId = Tenant::find($tenantId)->subscription->id;
        $adminId = User::where('tenant_id', $tenantId)->where('role', User::ROLE_SUPER_ADMIN)->value('id');
        $companyId = Company::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('id');

        $professional = $this->package('professional', 'Professional');

        // The ordinary subscription edit -- the same endpoint any other
        // plan change goes through. No complimentary-specific conversion
        // path exists, and none should.
        $this->actingAs($operator)->put(route('platform.tenants.subscription.update', $tenantId), [
            'package_id' => $professional->id,
            'type' => Subscription::TYPE_SUBSCRIPTION,
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'billing_mode' => Subscription::BILLING_MODE_PAID,
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
        ])->assertSessionHasNoErrors();

        $tenant = Tenant::find($tenantId);

        // NOTHING was recreated.
        $this->assertSame($tenantId, $tenant->id);
        $this->assertSame($subscriptionId, $tenant->subscription->id);
        $this->assertSame(1, Subscription::where('tenant_id', $tenantId)->count());
        $this->assertSame($adminId, User::where('tenant_id', $tenantId)->where('role', User::ROLE_SUPER_ADMIN)->value('id'));
        $this->assertSame($companyId, Company::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('id'));

        // And it is now a paying Professional customer.
        $this->assertSame('professional', $tenant->subscription->package->slug);
        $this->assertTrue($tenant->subscription->isBillable());
        $this->assertFalse($tenant->subscription->isComplimentary());

        // With Professional's entitlements, not Starter's.
        $granted = app(EntitlementService::class)->grantedWorkspaceKeys($tenant);
        $this->assertContains('hse', $granted);
        $this->assertContains('hr', $granted);
    }

    /* ==================================================================
     * ISOLATION
     * ================================================================== */

    public function test_a_complimentary_tenant_is_isolated_from_other_tenants(): void
    {
        $operator = $this->operator();

        $first = $this->verifiedRegistration(['company_display_name' => 'Galangan Aliran Jaya']);
        $second = $this->verifiedRegistration(['company_display_name' => 'Unrelated Yard']);

        $this->grant($operator, $first);
        $this->grant($operator, $second);

        $firstTenantId = $first->fresh()->tenant_id;
        $secondTenantId = $second->fresh()->tenant_id;

        $this->assertNotSame($firstTenantId, $secondTenantId);

        // Each organization's company belongs to its own tenant and to no
        // other.
        $this->assertSame(1, Company::withoutGlobalScopes()->where('tenant_id', $firstTenantId)->count());
        $this->assertSame(1, Company::withoutGlobalScopes()->where('tenant_id', $secondTenantId)->count());

        // Each Super Admin reaches exactly one tenant.
        $firstAdmin = User::where('tenant_id', $firstTenantId)->where('role', User::ROLE_SUPER_ADMIN)->first();
        $this->assertSame($firstTenantId, $firstAdmin->tenant_id);
        $this->assertNotSame($secondTenantId, $firstAdmin->tenant_id);
    }

    /* ==================================================================
     * THE PAYING PATH IS UNCHANGED
     * ================================================================== */

    /**
     * `provision()` was refactored to take its commercial terms as an
     * argument so one body builds both kinds of tenant. This asserts the
     * paying path came out of that refactor behaving exactly as before:
     * billable, priced from the catalogue, period from the ordered cycle.
     */
    public function test_a_paid_registration_still_provisions_as_a_billable_subscription(): void
    {
        $package = $this->package('professional', 'Professional');

        $registration = $this->verifiedRegistration([
            'status' => TenantRegistration::STATUS_PAID,
            'package_id' => $package->id,
            'billing_cycle' => 'yearly',
            'paid_at' => now(),
        ]);

        $tenant = app(TenantProvisioningService::class)->activate($registration);

        $this->assertNotNull($tenant);

        $subscription = $tenant->subscription;

        $this->assertSame(Subscription::BILLING_MODE_PAID, $subscription->billingMode());
        $this->assertTrue($subscription->isBillable());
        $this->assertSame(Subscription::CYCLE_YEARLY, $subscription->billing_cycle);
        $this->assertSame(now()->addYear()->toDateString(), $subscription->ends_at->toDateString());

        // The agreed price is still snapshotted from the catalogue at
        // activation -- the v2.60.0 protection against a later catalogue
        // edit repricing a live customer.
        $this->assertSame((float) $package->price_monthly, (float) $subscription->agreed_price_monthly);
        $this->assertSame((float) $package->price_yearly, (float) $subscription->agreed_price_yearly);
    }
}
