<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.51.0 -- self-service onboarding, and the boundaries that make it
 * safe to expose to the open internet.
 *
 * The rule every test here defends: NOTHING that grants access happens
 * before a payment the server itself verified. A registration is a
 * prospect, not a tenant. Reaching a success URL proves nothing.
 */
class SelfServiceOnboardingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The prospect's own workspace, by name. The tenants table always
     * holds a "Default Tenant" row created by the original tenancy
     * migration, so counting the table would be asserting against that
     * baseline rather than against this registration.
     */
    private function provisionedWorkspaces(): int
    {
        return Tenant::where('name', 'Contoh Industri')->count();
    }

    private function assertNoWorkspaceProvisioned(): void
    {
        $this->assertSame(0, $this->provisionedWorkspaces(), 'A workspace was provisioned when none should have been.');
    }

    private function seedPlans(): void
    {
        $this->seed(\Database\Seeders\PackageSeeder::class);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'contact_name' => 'Budi Santoso',
            'contact_email' => 'budi@contoh.test',
            'contact_phone' => '+62 811 0000 0000',
            'password' => 'rahasia-kuat-123',
            'password_confirmation' => 'rahasia-kuat-123',
            'company_legal_name' => 'PT Contoh Industri Nusantara',
            'company_display_name' => 'Contoh Industri',
            'company_address' => 'Jl. Industri No. 1',
            'company_city' => 'Batam',
            'company_province' => 'Kepulauan Riau',
            'company_country' => 'Indonesia',
            'plan' => 'professional',
            'billing_cycle' => 'yearly',
            'terms' => true,
        ], $overrides);
    }

    /** Registering creates a PROSPECT. No tenant, no company, no user account exists yet. */
    public function test_registration_creates_no_tenant_and_no_user(): void
    {
        Mail::fake();
        $this->seedPlans();

        $this->post(route('register.store'), $this->validPayload())->assertRedirect();

        $registration = TenantRegistration::firstOrFail();

        $this->assertSame(TenantRegistration::STATUS_PENDING_VERIFICATION, $registration->status);
        $this->assertNull($registration->tenant_id);
        $this->assertNull($registration->user_id);

        // The critical assertion: there is nothing to log in with.
        $this->assertNoWorkspaceProvisioned();
        $this->assertSame(0, User::where('email', 'budi@contoh.test')->count());

        // The password was hashed before it touched the database.
        $this->assertNotSame('rahasia-kuat-123', $registration->password);
        $this->assertTrue(Hash::check('rahasia-kuat-123', $registration->password));
    }

    /** The price is taken from the catalog, never from the request. */
    public function test_the_amount_is_derived_server_side_and_client_input_is_ignored(): void
    {
        Mail::fake();
        $this->seedPlans();

        $this->post(route('register.store'), $this->validPayload([
            'amount' => 1,
            'price' => 1,
            'currency' => 'USD',
        ]))->assertRedirect();

        $registration = TenantRegistration::firstOrFail();
        $professional = Package::where('slug', 'professional')->firstOrFail();

        $this->assertEquals((float) $professional->price_yearly, (float) $registration->amount);
        $this->assertSame('IDR', $registration->currency);
    }

    /** An unknown or non-public plan slug cannot enter checkout. */
    public function test_an_unknown_plan_is_rejected(): void
    {
        Mail::fake();
        $this->seedPlans();

        $this->post(route('register.store'), $this->validPayload(['plan' => 'platinum-unlimited']))
            ->assertSessionHasErrors('plan');

        $this->assertDatabaseCount('tenant_registrations', 0);
    }

    /** Registering twice with one email does not create a second live registration. */
    public function test_a_duplicate_email_is_refused(): void
    {
        Mail::fake();
        $this->seedPlans();

        $this->post(route('register.store'), $this->validPayload())->assertRedirect();
        $this->post(route('register.store'), $this->validPayload())->assertSessionHasErrors('contact_email');

        $this->assertDatabaseCount('tenant_registrations', 1);
    }

    /** An address already belonging to an IOMS user is refused too. */
    public function test_an_existing_user_email_is_refused(): void
    {
        Mail::fake();
        $this->seedPlans();

        $tenant = Tenant::create(['name' => 'ACME', 'slug' => 'acme']);
        User::create([
            'name' => 'Existing', 'email' => 'budi@contoh.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $this->post(route('register.store'), $this->validPayload())->assertSessionHasErrors('contact_email');
        $this->assertDatabaseCount('tenant_registrations', 0);
    }

    /** Checkout is refused until the email is confirmed. */
    public function test_checkout_requires_a_verified_email(): void
    {
        Mail::fake();
        $this->seedPlans();

        $this->post(route('register.store'), $this->validPayload())->assertRedirect();
        $registration = TenantRegistration::firstOrFail();

        $this->post(route('register.checkout', $registration->token))
            ->assertSessionHasErrors('payment');

        $this->assertDatabaseCount('invoices', 0);
    }

    /** Verifying the email issues a real invoice, and still activates nothing. */
    public function test_verifying_then_checking_out_issues_an_invoice_but_activates_nothing(): void
    {
        Mail::fake();
        $this->seedPlans();

        $this->post(route('register.store'), $this->validPayload())->assertRedirect();
        $registration = TenantRegistration::firstOrFail();

        $this->get(route('register.verify', $registration->token))->assertRedirect();
        $this->assertNotNull($registration->fresh()->email_verified_at);

        $this->post(route('register.checkout', $registration->token))->assertRedirect();

        $registration->refresh();
        $invoice = Invoice::firstOrFail();

        $this->assertSame(TenantRegistration::STATUS_AWAITING_PAYMENT, $registration->status);
        $this->assertSame($invoice->id, $registration->invoice_id);
        $this->assertNull($invoice->tenant_id, 'An onboarding invoice has no tenant until it is paid.');
        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->status);

        // Still nothing to sign in with.
        $this->assertNoWorkspaceProvisioned();
    }

    /** THE CENTRAL RULE: visiting the status page cannot activate anything. */
    public function test_reaching_the_status_page_never_activates_a_tenant(): void
    {
        Mail::fake();
        $this->seedPlans();

        $this->post(route('register.store'), $this->validPayload())->assertRedirect();
        $registration = TenantRegistration::firstOrFail();
        $this->get(route('register.verify', $registration->token));
        $this->post(route('register.checkout', $registration->token));

        // Hit it repeatedly, as a customer returning from a gateway would.
        foreach (range(1, 3) as $ignored) {
            $this->get(route('register.status', $registration->token))->assertOk();
        }

        $this->assertNoWorkspaceProvisioned();
        $this->assertSame(TenantRegistration::STATUS_AWAITING_PAYMENT, $registration->fresh()->status);
    }

    /** The status page must not leak the credentials or the URL token of a registration. */
    public function test_the_status_page_does_not_expose_the_password_hash(): void
    {
        Mail::fake();
        $this->seedPlans();

        $this->post(route('register.store'), $this->validPayload())->assertRedirect();
        $registration = TenantRegistration::firstOrFail();

        $this->get(route('register.status', $registration->token))
            ->assertOk()
            ->assertDontSee($registration->password);
    }

    /* ------------------------------------------------------------------ */
    /* Provisioning                                                        */
    /* ------------------------------------------------------------------ */

    private function paidRegistration(): TenantRegistration
    {
        $this->seedPlans();

        return TenantRegistration::create([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_PAID,
            'contact_name' => 'Budi Santoso',
            'contact_email' => 'budi@contoh.test',
            'password' => Hash::make('rahasia-kuat-123'),
            'company_legal_name' => 'PT Contoh Industri Nusantara',
            'company_display_name' => 'Contoh Industri',
            'company_address' => 'Jl. Industri No. 1',
            'company_city' => 'Batam',
            'company_province' => 'Kepulauan Riau',
            'company_country' => 'Indonesia',
            'package_id' => Package::where('slug', 'professional')->value('id'),
            'billing_cycle' => 'yearly',
            'amount' => 9990000,
            'currency' => 'IDR',
            'email_verified_at' => now(),
            'paid_at' => now(),
        ]);
    }

    /** A paid registration provisions a complete, usable tenant. */
    public function test_a_paid_registration_provisions_a_usable_tenant(): void
    {
        Mail::fake();
        $registration = $this->paidRegistration();

        $tenant = app(TenantProvisioningService::class)->activate($registration);

        $this->assertNotNull($tenant);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status);

        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($company);
        $this->assertSame('Contoh Industri', $company->name);

        $admin = User::where('tenant_id', $tenant->id)->first();
        $this->assertNotNull($admin);
        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->role);
        // The administrator can sign in with the password they chose --
        // IOMS never emails a credential.
        $this->assertTrue(Hash::check('rahasia-kuat-123', $admin->password));

        $subscription = Subscription::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertSame('yearly', $subscription->billing_cycle);

        $this->assertSame(TenantRegistration::STATUS_PROVISIONED, $registration->fresh()->status);
    }

    /** An UNPAID registration can never be provisioned, whoever asks. */
    public function test_an_unpaid_registration_is_never_provisioned(): void
    {
        Mail::fake();
        $registration = $this->paidRegistration();
        $registration->update(['status' => TenantRegistration::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $this->assertNull(app(TenantProvisioningService::class)->activate($registration));
        $this->assertNoWorkspaceProvisioned();
    }

    /** Provisioning twice -- a duplicate webhook -- yields exactly one tenant. */
    public function test_provisioning_is_idempotent(): void
    {
        Mail::fake();
        $registration = $this->paidRegistration();

        $service = app(TenantProvisioningService::class);
        $first = $service->activate($registration);
        $second = $service->activate($registration->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->provisionedWorkspaces());
        $this->assertSame(1, User::where('tenant_id', $first->id)->count());
        $this->assertSame(1, Subscription::where('tenant_id', $first->id)->count());
    }

    /** The company identity the prospect submitted flows into their own tenant's settings. */
    public function test_provisioning_writes_the_company_identity_into_the_new_tenant(): void
    {
        Mail::fake();
        $registration = $this->paidRegistration();

        $tenant = app(TenantProvisioningService::class)->activate($registration);

        $this->assertDatabaseHas('company_settings', [
            'tenant_id' => $tenant->id, 'key' => 'company_legal_name', 'value' => 'PT Contoh Industri Nusantara',
        ]);
        $this->assertDatabaseHas('company_settings', [
            'tenant_id' => $tenant->id, 'key' => 'company_city', 'value' => 'Batam',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Webhook                                                             */
    /* ------------------------------------------------------------------ */

    /** An unsigned or wrongly-signed notification is refused before anything is read. */
    public function test_an_unverified_webhook_is_rejected_and_activates_nothing(): void
    {
        config(['payment.gateway' => 'midtrans']);
        config(['payment.midtrans.server_key' => 'test-server-key']);
        config(['payment.midtrans.client_key' => 'test-client-key']);

        $registration = $this->paidRegistration();
        $registration->update(['status' => TenantRegistration::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-1', 'tenant_id' => null, 'registration_id' => $registration->id,
            'amount' => 9990000, 'currency' => 'IDR', 'status' => Invoice::STATUS_ISSUED,
        ]);

        $this->postJson(route('webhooks.payment.midtrans'), [
            'order_id' => 'INV'.$invoice->id.'-20260917120000',
            'status_code' => '200',
            'gross_amount' => '9990000.00',
            'transaction_status' => 'settlement',
            'signature_key' => 'obviously-not-the-real-signature',
        ])->assertForbidden();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertNoWorkspaceProvisioned();
    }

    /** A correctly-signed settlement marks the invoice paid and provisions exactly one tenant. */
    public function test_a_verified_settlement_provisions_the_tenant_exactly_once(): void
    {
        Mail::fake();
        $serverKey = 'test-server-key';
        config(['payment.gateway' => 'midtrans']);
        config(['payment.midtrans.server_key' => $serverKey]);
        config(['payment.midtrans.client_key' => 'test-client-key']);

        $registration = $this->paidRegistration();
        $registration->update(['status' => TenantRegistration::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-2', 'tenant_id' => null, 'registration_id' => $registration->id,
            'amount' => 9990000, 'currency' => 'IDR', 'status' => Invoice::STATUS_ISSUED,
        ]);

        $orderId = 'INV'.$invoice->id.'-20260917120000';
        PaymentTransaction::create([
            'invoice_id' => $invoice->id, 'gateway' => 'midtrans', 'gateway_reference' => $orderId,
            'status' => 'pending', 'amount' => 9990000, 'currency' => 'IDR',
        ]);

        $payload = [
            'order_id' => $orderId,
            'status_code' => '200',
            'gross_amount' => '9990000.00',
            'transaction_status' => 'settlement',
            'transaction_id' => 'abc-123',
        ];
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$serverKey);

        $this->postJson(route('webhooks.payment.midtrans'), $payload)->assertOk();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(TenantRegistration::STATUS_PROVISIONED, $registration->fresh()->status);
        $this->assertSame(1, $this->provisionedWorkspaces());

        // DUPLICATE DELIVERY: gateways retry. It must not double-apply.
        $this->postJson(route('webhooks.payment.midtrans'), $payload)->assertOk();

        $this->assertSame(1, $this->provisionedWorkspaces());
        $this->assertSame(1, PaymentWebhookEvent::where('gateway', 'midtrans')->count());
    }

    /** A failed payment must leave the registration exactly where it was. */
    public function test_a_failed_payment_activates_nothing(): void
    {
        $serverKey = 'test-server-key';
        config(['payment.gateway' => 'midtrans']);
        config(['payment.midtrans.server_key' => $serverKey]);
        config(['payment.midtrans.client_key' => 'test-client-key']);

        $registration = $this->paidRegistration();
        $registration->update(['status' => TenantRegistration::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-3', 'tenant_id' => null, 'registration_id' => $registration->id,
            'amount' => 9990000, 'currency' => 'IDR', 'status' => Invoice::STATUS_ISSUED,
        ]);

        $orderId = 'INV'.$invoice->id.'-20260917120000';
        $payload = [
            'order_id' => $orderId, 'status_code' => '202', 'gross_amount' => '9990000.00',
            'transaction_status' => 'deny', 'transaction_id' => 'abc-456',
        ];
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$serverKey);

        $this->postJson(route('webhooks.payment.midtrans'), $payload)->assertOk();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertSame(TenantRegistration::STATUS_AWAITING_PAYMENT, $registration->fresh()->status);
        $this->assertNoWorkspaceProvisioned();
    }

    /** A settlement for LESS than the invoice is not treated as payment. */
    public function test_an_underpayment_is_not_treated_as_settled(): void
    {
        $serverKey = 'test-server-key';
        config(['payment.gateway' => 'midtrans']);
        config(['payment.midtrans.server_key' => $serverKey]);
        config(['payment.midtrans.client_key' => 'test-client-key']);

        $registration = $this->paidRegistration();
        $registration->update(['status' => TenantRegistration::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-4', 'tenant_id' => null, 'registration_id' => $registration->id,
            'amount' => 9990000, 'currency' => 'IDR', 'status' => Invoice::STATUS_ISSUED,
        ]);

        $orderId = 'INV'.$invoice->id.'-20260917120000';
        $payload = [
            'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => '1000.00',
            'transaction_status' => 'settlement', 'transaction_id' => 'abc-789',
        ];
        $payload['signature_key'] = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].$serverKey);

        $this->postJson(route('webhooks.payment.midtrans'), $payload)->assertOk();

        $this->assertSame(Invoice::STATUS_ISSUED, $invoice->fresh()->status);
        $this->assertNoWorkspaceProvisioned();
    }

    /** Master Admin's re-provision action cannot activate an unpaid registration. */
    public function test_master_admin_cannot_provision_an_unpaid_registration(): void
    {
        $registration = $this->paidRegistration();
        $registration->update(['status' => TenantRegistration::STATUS_AWAITING_PAYMENT, 'paid_at' => null]);

        $platform = User::create([
            'name' => 'Master', 'email' => 'master@ioms.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_PLATFORM_ADMIN, 'tenant_id' => null, 'is_active' => true,
        ]);

        $this->actingAs($platform)
            ->post(route('platform.registrations.provision', $registration->id))
            ->assertSessionHasErrors('registration');

        $this->assertNoWorkspaceProvisioned();
    }

    /** A tenant administrator cannot reach the onboarding pipeline at all. */
    public function test_a_tenant_admin_cannot_reach_the_registrations_surface(): void
    {
        $tenant = Tenant::create(['name' => 'ACME', 'slug' => 'acme']);
        Company::withoutGlobalScopes()->create(['name' => 'ACME', 'tenant_id' => $tenant->id]);
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin@acme.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('platform.registrations'))->assertForbidden();
    }
}
