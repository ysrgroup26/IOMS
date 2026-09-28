<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Payments\DuitkuGateway;
use App\Services\Payments\NullPaymentGateway;
use App\Services\SchemaStatusService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * v2.84.1 -- ADMIN CONTEXT, WORKSPACE BOUNDARIES, AND THE BILLING FLOW.
 *
 * Three corrections, and each one is a boundary rather than a feature:
 *
 *   ADMIN SPACE IS A CONTEXT. Entering it and then clicking "Dashboard"
 *   dropped the user into HSE, so administration behaved like a page inside
 *   an operational workspace rather than a place of its own.
 *
 *   ADMINISTRATIVE AUTHORITY BELONGS TO ADMIN SPACE. User administration was
 *   reachable from inside HSE -- in its sidebar, on its Overview, and
 *   through the routing layer, which v2.46.0 had deliberately opened.
 *
 *   A TENANT MUST BE ABLE TO PAY. The renewal path existed end to end and
 *   stopped at "contact IOMS Billing", because no provider was implemented
 *   behind the abstraction. Duitku is now implemented at that boundary.
 */
class AdminContextAndBillingFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['saas.enforce_entitlement' => true, 'saas.enforce_workspace_entitlement' => true]);
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
    }

    private function tenantOn(string $slug, array $subscription = []): Tenant
    {
        $package = Package::where('slug', $slug)->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard '.$slug, 'slug' => 'yard-'.$slug.'-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YRD', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
            ...$subscription,
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        return $this->tenant;
    }

    private function userWith(string $role, array $overrides = []): User
    {
        return User::create([
            'name' => ucfirst($role), 'email' => $role.'-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'), 'role' => $role,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function layout(): string
    {
        return file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
    }

    /* ==================================================================
     * 1-3. ADMIN SPACE IS A CONTEXT, NOT A PAGE
     * ================================================================== */

    /**
     * THE DEFECT: the two operational links in the header stayed visible
     * inside Admin Space, and "Dashboard" is a redirect into a workspace on
     * any plan without the Global Company Dashboard. Clicking it from
     * Administration Overview landed the user in HSE.
     */
    public function test_the_operational_header_links_are_absent_inside_admin_space(): void
    {
        $layout = $this->layout();

        $this->assertStringContainsString("auth?.user?.has_global_dashboard && space !== 'admin'", $layout,
            'The Global Dashboard link must not be offered inside Admin Space.');
        $this->assertStringContainsString("{space !== 'admin' && (\n                <Link\n                    href={route('calendar.index')}", $layout,
            'The Calendar link must not be offered inside Admin Space.');
    }

    /** Exactly one of the two contexts is lit, and the state is announced. */
    public function test_the_header_shows_which_context_is_active(): void
    {
        $layout = $this->layout();

        // The workspace selector goes quiet inside Admin Space...
        $this->assertStringContainsString("space === 'admin'\n                                ? 'border border-transparent", $layout);
        $this->assertStringContainsString("aria-current={space !== 'admin' ? 'true' : undefined}", $layout);

        // ...and the Admin Space entry lights up, without disappearing.
        $this->assertStringContainsString("aria-current={space === 'admin' ? 'page' : undefined}", $layout);
        $this->assertStringContainsString("{auth?.user?.can_access_admin_space && (", $layout);

        // And the selector still names no workspace while none is active.
        $this->assertStringContainsString("{space === 'admin' ? 'Workspaces' :", $layout);
    }

    /** Administration Overview is Admin Space's home, on every plan. */
    public function test_admin_space_stays_inside_admin_space(): void
    {
        $this->tenantOn('starter');
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);

        $this->actingAs($admin)->get(route('admin.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Overview'));

        // The mobile bar carries the same rule as the header.
        $this->assertStringContainsString(
            "space !== 'admin'",
            file_get_contents(resource_path('js/Components/shared/MobileBottomNav.jsx')),
            'The mobile Dashboard tab must not lead out of Admin Space either.'
        );
    }

    /* ==================================================================
     * 4-6. ADMINISTRATIVE AUTHORITY DOES NOT LEAK INTO A WORKSPACE
     * ================================================================== */

    /**
     * The routing layer is the boundary, not the sidebar. v2.46.0 listed
     * `settings` under `hse` so a department-scoped HSE user could open
     * Settings > Users; that is administration reached through an
     * operational department's door.
     */
    public function test_settings_is_owned_by_administration_alone(): void
    {
        $departments = config('departments');

        $this->assertContains('settings', $departments['administration']);

        foreach (['hse', 'hr', 'logistics', 'management'] as $workspace) {
            $this->assertNotContains(
                'settings',
                $departments[$workspace] ?? [],
                "Administration must not be reachable through the {$workspace} department."
            );
        }
    }

    /** And it is enforced, not merely unlisted. */
    public function test_a_department_scoped_user_cannot_reach_administration(): void
    {
        $this->tenantOn('business');

        foreach (['hse', 'hr', 'logistics'] as $department) {
            $user = $this->userWith(User::ROLE_HSE, ['department_key' => $department]);

            $this->actingAs($user)->get(route('settings.index'))->assertForbidden();
            $this->actingAs($user)->get(route('activity-center.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.index'))->assertForbidden();
        }
    }

    /** No operational workspace offers an administrative entry point. */
    public function test_no_workspace_navigation_offers_administration(): void
    {
        $registry = file_get_contents(resource_path('js/lib/workspaces.js'));

        // Everything from the HSE entry to the Admin Space entry is the
        // operational half of the registry.
        $operational = substr(
            $registry,
            strpos($registry, "key: 'hr',"),
            strpos($registry, "key: 'administration',") - strpos($registry, "key: 'hr',")
        );

        foreach (['settings.index', 'activity-center.index', 'subscription.billing'] as $administrative) {
            $this->assertStringNotContainsString(
                $administrative,
                $operational,
                "'{$administrative}' is administration and must live only in Admin Space."
            );
        }

        // And the HSE Overview stopped linking to user administration too.
        $this->assertStringNotContainsString(
            "href: 'settings.index'",
            file_get_contents(resource_path('js/Pages/Hse/Dashboard.jsx'))
        );
    }

    /* ==================================================================
     * 7-8. WAREHOUSE LOGISTICS IS ONE WORKSPACE, WITH ONE NAME
     * ================================================================== */

    public function test_warehouse_logistics_is_one_workspace_with_one_name(): void
    {
        $this->assertSame(['hse', 'hr', 'logistics', 'management'], config('plans.operational'));

        // ONE name, and it is the one the workspace is sold under. The
        // product carried "Logistics / PPIC" and then "Logistics / Warehouse"
        // for the same thing, which reasonably reads as two workspaces.
        $this->assertStringContainsString(
            "label: 'Warehouse Logistics'",
            file_get_contents(resource_path('js/lib/workspaces.js'))
        );
        $this->assertStringNotContainsString("label: 'Logistics / PPIC'", file_get_contents(resource_path('js/lib/workspaces.js')));

        $this->assertStringContainsString(
            '<Head title="Warehouse Logistics Overview"',
            file_get_contents(resource_path('js/Pages/Logistics/Dashboard.jsx'))
        );
    }

    /* ==================================================================
     * 9-10. THE SCHEMA STATUS THAT EXPLAINS A 500
     * ================================================================== */

    /**
     * The Support 500 in a deployed environment was a pending migration:
     * with the support tables absent, that page is the only one in Master
     * Admin that fails. It now says so instead of returning a blank 500.
     */
    public function test_the_support_queue_reports_a_missing_schema_instead_of_failing(): void
    {
        $operator = User::create([
            'name' => 'Operator', 'email' => 'ops-'.uniqid().'@ioms.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_PLATFORM_ADMIN,
            'tenant_id' => null, 'is_active' => true,
        ]);

        Schema::drop('support_ticket_messages');
        Schema::drop('support_tickets');

        $this->actingAs($operator)->get(route('platform.support'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Platform/Support/Index')
                ->where('schema_missing', ['support_tickets', 'support_ticket_messages'])
            );
    }

    /** The service reports; it never migrates. */
    public function test_the_schema_status_service_only_reports(): void
    {
        $snapshot = app(SchemaStatusService::class)->snapshot();

        $this->assertArrayHasKey('pending_count', $snapshot);
        $this->assertIsInt($snapshot['pending_count']);
        $this->assertSame([], app(SchemaStatusService::class)->missingTables(['users', 'tenants']));
        $this->assertSame(['definitely_not_a_table'], app(SchemaStatusService::class)->missingTables(['definitely_not_a_table']));
    }

    /* ==================================================================
     * 11-15. THE BILLING FLOW, AND THE PROVIDER BEHIND IT
     * ================================================================== */

    /** No provider configured means NO payment -- never a fake success. */
    public function test_an_unconfigured_deployment_takes_no_payment(): void
    {
        config(['payment.gateway' => null]);

        $gateway = app(PaymentGatewayInterface::class);

        $this->assertInstanceOf(NullPaymentGateway::class, $gateway);
        $this->assertFalse($gateway->isConfigured());
    }

    /** Naming Duitku without credentials does not half-activate it. */
    public function test_duitku_without_credentials_falls_back_to_the_null_gateway(): void
    {
        config(['payment.gateway' => 'duitku', 'payment.duitku.merchant_code' => '', 'payment.duitku.api_key' => '']);

        $this->assertInstanceOf(NullPaymentGateway::class, app(PaymentGatewayInterface::class));
    }

    /** With credentials, the adapter binds and identifies itself. */
    public function test_duitku_binds_at_the_existing_provider_boundary(): void
    {
        $this->configureDuitku();

        $gateway = app(PaymentGatewayInterface::class);

        $this->assertInstanceOf(DuitkuGateway::class, $gateway);
        $this->assertTrue($gateway->isConfigured());
        $this->assertSame('duitku', $gateway->gatewayName());
        // A hosted redirect flow publishes nothing to the browser.
        $this->assertSame([], $gateway->clientConfig());
    }

    /**
     * THE SIGNATURE IS THE WHOLE SECURITY MODEL, so it is asserted against
     * the formula Duitku documents rather than against the implementation.
     *
     *   callback signature = HMAC_SHA256(merchantCode + amount + merchantOrderId, apiKey)
     */
    public function test_a_duitku_callback_is_trusted_only_when_its_signature_matches(): void
    {
        $this->configureDuitku();
        $gateway = app(PaymentGatewayInterface::class);

        $payload = ['merchantCode' => 'DXXXX', 'amount' => '189000', 'merchantOrderId' => 'INV7-20260101120000', 'resultCode' => '00'];
        $payload['signature'] = hash_hmac('sha256', 'DXXXX189000INV7-20260101120000', 'test-api-key');

        $this->assertTrue($gateway->verifyWebhookSignature($payload));

        // Every one of these is a forgery, and each must be refused.
        $this->assertFalse($gateway->verifyWebhookSignature([...$payload, 'signature' => 'nope']));
        $this->assertFalse($gateway->verifyWebhookSignature([...$payload, 'amount' => '1']), 'A changed amount must invalidate the signature.');
        $this->assertFalse($gateway->verifyWebhookSignature([...$payload, 'signature' => '']));
        unset($payload['signature']);
        $this->assertFalse($gateway->verifyWebhookSignature($payload), 'An unsigned payload is never trusted.');
    }

    /** resultCode is mapped exactly, and an unknown code never settles. */
    public function test_duitku_result_codes_map_to_the_shared_vocabulary(): void
    {
        $this->configureDuitku();
        $gateway = app(PaymentGatewayInterface::class);

        $this->assertSame('paid', $gateway->handleWebhook(['resultCode' => '00', 'merchantOrderId' => 'INV1-x'])->status);
        $this->assertSame('failed', $gateway->handleWebhook(['resultCode' => '01', 'merchantOrderId' => 'INV1-x'])->status);
        $this->assertSame('pending', $gateway->handleWebhook(['resultCode' => '99', 'merchantOrderId' => 'INV1-x'])->status);
        $this->assertSame('pending', $gateway->handleWebhook(['merchantOrderId' => 'INV1-x'])->status);
    }

    /** An unsigned callback changes nothing at all. */
    public function test_an_unsigned_duitku_callback_settles_nothing(): void
    {
        $this->configureDuitku();
        $invoice = $this->renewalInvoice();

        $this->postJson(route('webhooks.payment.duitku'), [
            'merchantCode' => 'DXXXX', 'amount' => (string) (int) $invoice->amount,
            'merchantOrderId' => 'INV'.$invoice->id.'-20260101120000', 'resultCode' => '00',
        ])->assertForbidden();

        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    /**
     * THE WHOLE POINT: a signature-verified Duitku callback settles the
     * invoice and extends the subscription, through the SAME shared path
     * Midtrans uses -- and a replay does not extend it twice.
     */
    public function test_a_verified_duitku_callback_settles_the_invoice_and_extends_the_period(): void
    {
        $this->configureDuitku();
        $invoice = $this->renewalInvoice();
        $subscription = $this->tenant->subscription;
        $endsBefore = $subscription->ends_at->copy();

        $response = $this->postDuitkuCallback($invoice);
        $response->assertOk();

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame('duitku', $invoice->payment_method, 'The settlement must record the provider that took it, not a hardcoded one.');

        $this->assertTrue(
            $subscription->fresh()->ends_at->greaterThan($endsBefore),
            'A verified payment must extend the period.'
        );

        // Replay: the same transition, delivered twice.
        $extendedTo = $subscription->fresh()->ends_at->copy();
        $this->postDuitkuCallback($invoice)->assertOk();
        $this->assertTrue($subscription->fresh()->ends_at->equalTo($extendedTo), 'A replayed callback must not extend twice.');
    }

    /** A lapsed tenant can still reach billing and start a renewal. */
    public function test_a_lapsed_tenant_can_still_reach_billing_and_renew(): void
    {
        $this->tenantOn('starter', ['ends_at' => now()->subDays(Subscription::graceDays() + 5)]);
        $admin = $this->userWith(User::ROLE_SUPER_ADMIN);

        $this->assertSame(Subscription::LIFECYCLE_LAPSED, $this->tenant->subscription->lifecycleState());

        // Admin Space and its billing page stay open -- the page that
        // explains the problem must never be behind the problem.
        $this->actingAs($admin)->get(route('admin.index'))->assertOk();
        $this->actingAs($admin)->get(route('subscription.billing'))->assertOk();

        // And the renewal itself is not refused by the read-only guard.
        $this->actingAs($admin)->post(route('subscription.renew'))->assertRedirect();
        $this->assertDatabaseHas('invoices', ['tenant_id' => $this->tenant->id, 'status' => Invoice::STATUS_ISSUED]);
    }

    /* ==================================================================
     * Fixtures
     * ================================================================== */

    private function configureDuitku(): void
    {
        config([
            'payment.gateway' => 'duitku',
            'payment.duitku.merchant_code' => 'DXXXX',
            'payment.duitku.api_key' => 'test-api-key',
            'payment.duitku.is_production' => false,
        ]);

        app()->forgetInstance(PaymentGatewayInterface::class);
    }

    private function renewalInvoice(): Invoice
    {
        $this->tenantOn('starter');

        return Invoice::create([
            'invoice_number' => 'INV-TEST-'.uniqid(),
            'tenant_id' => $this->tenant->id,
            'subscription_id' => $this->tenant->subscription->id,
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'amount' => 189000,
            'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED,
            'due_date' => now()->addDays(7),
            'purpose' => Invoice::PURPOSE_RENEWAL,
        ]);
    }

    private function postDuitkuCallback(Invoice $invoice): \Illuminate\Testing\TestResponse
    {
        $orderId = 'INV'.$invoice->id.'-20260101120000';
        $amount = (string) (int) $invoice->amount;

        PaymentTransaction::firstOrCreate(
            ['gateway_reference' => $orderId],
            [
                'invoice_id' => $invoice->id, 'gateway' => 'duitku', 'status' => 'pending',
                'amount' => $invoice->amount, 'currency' => $invoice->currency,
            ]
        );

        return $this->postJson(route('webhooks.payment.duitku'), [
            'merchantCode' => 'DXXXX',
            'amount' => $amount,
            'merchantOrderId' => $orderId,
            'resultCode' => '00',
            'reference' => 'DK-REF-1',
            'signature' => hash_hmac('sha256', 'DXXXX'.$amount.$orderId, 'test-api-key'),
        ]);
    }
}
