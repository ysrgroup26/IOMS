<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\TenantRegistration;
use App\Models\User;
use App\Support\CurrentTenant;
use App\Support\LegalDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * v2.55.0 -- public commercial readiness.
 *
 * Covers the things a payment provider verifying this merchant would look
 * for, and the boundaries that must survive them being added: real policy
 * pages, a reachable contact page, an invoice a customer can download, a
 * branded checkout, and — most importantly — the unchanged rule that
 * nothing the browser does can activate a subscription.
 */
class PublicReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function package(string $slug = 'professional'): Package
    {
        return Package::create([
            'name' => ucfirst($slug), 'slug' => $slug,
            'price_monthly' => 999000, 'price_yearly' => 9990000, 'currency' => 'IDR',
            'max_users' => 50, 'max_companies' => 2, 'is_active' => true, 'is_public' => true,
        ]);
    }

    private function registrationWithInvoice(): array
    {
        $package = $this->package();

        $registration = TenantRegistration::create([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_AWAITING_PAYMENT,
            'contact_name' => 'Budi Santoso',
            'contact_email' => 'budi@contoh.test',
            'password' => bcrypt('secret-pass-1'),
            'company_legal_name' => 'PT Contoh Industri',
            'company_address' => 'Jl. Industri 1',
            'company_city' => 'Batam',
            'company_province' => 'Kepulauan Riau',
            'package_id' => $package->id,
            'billing_cycle' => 'yearly',
            'amount' => 9990000,
            'currency' => 'IDR',
            'email_verified_at' => now(),
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-0001',
            'tenant_id' => null,
            'registration_id' => $registration->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addYear()->toDateString(),
            'amount' => 9990000,
            'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $registration->update(['invoice_id' => $invoice->id]);

        return [$registration->fresh(), $invoice];
    }

    /* ================================================================
     * 1. THE POLICY PAGES ARE REAL
     * ================================================================ */

    public static function legalRoutes(): array
    {
        return [
            'terms' => ['legal.terms', 'Terms of Service'],
            'privacy' => ['legal.privacy', 'Privacy Policy'],
            'refunds' => ['legal.refunds', 'Refund & Cancellation Policy'],
        ];
    }

    #[DataProvider('legalRoutes')]
    public function test_each_policy_page_renders(string $routeName, string $title): void
    {
        $this->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/LegalDocument')
                ->where('title', $title)
                ->has('sections')
                ->etc()
            );
    }

    /**
     * The placeholder these replaced said "This page is being prepared",
     * which is not a document a merchant can be verified against.
     */
    public function test_no_policy_page_is_still_a_placeholder(): void
    {
        foreach (['legal.terms', 'legal.privacy', 'legal.refunds'] as $routeName) {
            $response = $this->get(route($routeName))->assertOk();

            // Asserted against the DOCUMENT, not the raw HTML. The shared
            // Inertia props carry the whole IOMS version history, and this
            // release's own changelog entry quotes the placeholder string it
            // replaced -- so a page-wide assertDontSee() would fail on the
            // release note describing the fix.
            $sections = $response->viewData('page')['props']['sections'];

            $this->assertNotEmpty($sections);
            $this->assertStringNotContainsString('This page is being prepared', json_encode($sections));

            foreach ($sections as $section) {
                $this->assertNotEmpty($section['body'], "Section '{$section['heading']}' rendered with no clauses.");
            }
        }
    }

    public function test_the_contact_page_publishes_the_ioms_mailboxes(): void
    {
        $this->get(route('contact'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Contact')
                ->where('emails.support', config('ioms.emails.support'))
                ->where('emails.billing', config('ioms.emails.billing'))
            );
    }

    /* ================================================================
     * 2. NOTHING IS FABRICATED WHEN A FACT IS MISSING
     * ================================================================ */

    /**
     * The whole point of building the documents server-side: a clause whose
     * underlying fact is unconfigured must DISAPPEAR, not render with a
     * placeholder that reads like a statement.
     */
    public function test_clauses_are_omitted_when_the_legal_identity_is_not_configured(): void
    {
        config([
            'ioms.legal.entity_name' => null,
            'ioms.legal.address' => null,
            'ioms.legal.jurisdiction' => null,
        ]);

        $text = json_encode(LegalDocuments::terms());

        $this->assertStringNotContainsString(':entity', $text);
        $this->assertStringNotContainsString(':address', $text);
        $this->assertStringNotContainsString(':jurisdiction', $text);
        $this->assertStringNotContainsString('dioperasikan oleh', $text);
        $this->assertNull(LegalDocuments::operator());
    }

    public function test_clauses_appear_once_the_legal_identity_is_configured(): void
    {
        config([
            'ioms.legal.entity_name' => 'PT Contoh Operator',
            'ioms.legal.jurisdiction' => 'Republik Indonesia',
        ]);

        $text = json_encode(LegalDocuments::terms());

        $this->assertStringContainsString('PT Contoh Operator', $text);
        $this->assertStringContainsString('Republik Indonesia', $text);
        $this->assertSame('PT Contoh Operator', LegalDocuments::operator());
    }

    /* ================================================================
     * 3. THE OFFICIAL MAILBOXES
     * ================================================================ */

    public function test_the_four_mailboxes_default_to_the_official_domain(): void
    {
        foreach (['support', 'billing', 'noreply', 'hello'] as $box) {
            $this->assertStringEndsWith('@iomsuite.com', config("ioms.emails.$box"));
        }
    }

    /* ================================================================
     * 4. THE INVOICE PDF, AND WHO MAY READ IT
     * ================================================================ */

    public function test_a_prospect_can_download_their_invoice_with_the_registration_token(): void
    {
        [$registration] = $this->registrationWithInvoice();

        $response = $this->get(route('register.invoice', $registration->token));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_an_unknown_registration_token_cannot_reach_an_invoice(): void
    {
        $this->registrationWithInvoice();

        $this->get(route('register.invoice', str_repeat('z', 64)))->assertNotFound();
    }

    /**
     * `invoices` carries no company_id, so it inherits nothing from
     * Company's global scopes: without the explicit tenant check, route
     * model binding would hand over another organization's invoice on a
     * guessed id.
     */
    public function test_an_administrator_cannot_download_another_organizations_invoice(): void
    {
        $tenantA = Tenant::create(['name' => 'Org A', 'slug' => 'org-a', 'status' => Tenant::STATUS_ACTIVE]);
        $tenantB = Tenant::create(['name' => 'Org B', 'slug' => 'org-b', 'status' => Tenant::STATUS_ACTIVE]);

        Company::withoutGlobalScopes()->create(['name' => 'A Yard', 'tenant_id' => $tenantA->id, 'is_active' => true]);
        app(CurrentTenant::class)->set($tenantA);

        $adminA = User::create([
            'name' => 'Admin A', 'email' => 'a@acme.test', 'password' => bcrypt('secret-pass-1'),
            'role' => 'super_admin', 'tenant_id' => $tenantA->id, 'is_active' => true,
        ]);

        $foreign = Invoice::create([
            'invoice_number' => 'INV-B-0001',
            'tenant_id' => $tenantB->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'amount' => 999000, 'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $this->actingAs($adminA)
            ->get(route('subscription.invoices.pdf', $foreign->id))
            ->assertNotFound();
    }

    public function test_an_administrator_can_download_their_own_organizations_invoice(): void
    {
        $tenant = Tenant::create(['name' => 'Org A', 'slug' => 'org-a', 'status' => Tenant::STATUS_ACTIVE]);
        Company::withoutGlobalScopes()->create(['name' => 'A Yard', 'tenant_id' => $tenant->id, 'is_active' => true]);
        app(CurrentTenant::class)->set($tenant);

        $admin = User::create([
            'name' => 'Admin', 'email' => 'a@acme.test', 'password' => bcrypt('secret-pass-1'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-A-0001',
            'tenant_id' => $tenant->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->addMonth()->toDateString(),
            'amount' => 999000, 'currency' => 'IDR',
            'status' => Invoice::STATUS_PAID,
            'due_date' => now()->addDays(7)->toDateString(),
        ]);

        $response = $this->actingAs($admin)->get(route('subscription.invoices.pdf', $invoice->id));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    /* ================================================================
     * 5. CHECKOUT SHOWS AN IOMS ORDER, AND ACTIVATES NOTHING
     * ================================================================ */

    /**
     * With no gateway configured there is no checkout session to render, so
     * the page must send the customer back to the honest status page rather
     * than show a payment button that cannot work.
     */
    public function test_the_checkout_page_redirects_when_no_payment_session_exists(): void
    {
        [$registration] = $this->registrationWithInvoice();

        $this->get(route('register.pay', $registration->token))
            ->assertRedirect(route('register.status', $registration->token));
    }

    /**
     * The branded order summary, exercised with a NON-PRODUCTION sandbox
     * configuration. No production credentials exist and none are implied:
     * this asserts the page IOMS renders, not that a payment can be taken.
     */
    public function test_the_checkout_page_shows_the_ioms_order_and_never_leaks_the_server_key(): void
    {
        [$registration, $invoice] = $this->registrationWithInvoice();

        config([
            'payment.gateway' => 'midtrans',
            'payment.midtrans.server_key' => 'SB-Mid-server-TESTONLY',
            'payment.midtrans.client_key' => 'SB-Mid-client-TESTONLY',
            'payment.midtrans.is_production' => false,
        ]);

        \App\Models\PaymentTransaction::create([
            'invoice_id' => $invoice->id,
            'gateway' => 'midtrans',
            'gateway_reference' => 'INV'.$invoice->id.'-20260908000000',
            'status' => \App\Models\PaymentTransaction::STATUS_PENDING,
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/testonly',
            'checkout_token' => 'snap-token-testonly',
        ]);

        $response = $this->get(route('register.pay', $registration->token));

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->component('Public/Checkout')
            ->where('order.invoice_number', 'INV-TEST-0001')
            ->where('order.currency', 'IDR')
            ->where('payment.snap_token', 'snap-token-testonly')
            ->where('payment.client_key', 'SB-Mid-client-TESTONLY')
            // v2.56.0: an annual order restates the discount at the moment
            // of payment, from the same server-side derivation the Pricing
            // page and Get Started use.
            ->where('order.annual_saving.monthly_equivalent_formatted', 'Rp11.988.000')
            ->where('order.annual_saving.formatted', 'Rp1.998.000')
            ->where('order.annual_saving.percent', 17)
            ->etc()
        );

        // The rendered page carries the amounts, not just the props.
        $response->assertSee('Rp11.988.000')->assertSee('Rp1.998.000');

        // The server key signs webhooks. It must never reach a browser.
        $response->assertDontSee('SB-Mid-server-TESTONLY');
    }

    /**
     * THE RULE THIS RELEASE MUST NOT WEAKEN. The status page is where every
     * browser-side payment outcome lands, and it is the URL the provider
     * redirects back to. Reaching it must change nothing at all.
     */
    public function test_reaching_the_status_page_cannot_activate_a_subscription(): void
    {
        [$registration] = $this->registrationWithInvoice();

        $this->get(route('register.status', $registration->token))->assertOk();
        $this->get(route('register.status', $registration->token))->assertOk();

        $registration->refresh();

        $this->assertSame(TenantRegistration::STATUS_AWAITING_PAYMENT, $registration->status);
        $this->assertSame(Invoice::STATUS_ISSUED, $registration->invoice->status);
        // No workspace was created FOR THIS REGISTRATION. (A default
        // tenant row predates every test from the tenancy migration, so a
        // bare count would assert the wrong thing.)
        $this->assertDatabaseMissing('tenants', ['name' => 'PT Contoh Industri']);
        $this->assertDatabaseMissing('users', ['email' => $registration->contact_email]);
        $this->assertNull($registration->tenant_id);
    }

    /* ================================================================
     * 6. THE PUBLIC SITE STILL PRESENTS A REAL COMMERCIAL PRODUCT
     * ================================================================ */

    public function test_the_faq_no_longer_sells_ptw_access_as_capacity(): void
    {
        $response = $this->get(route('faq'));

        $response->assertOk();

        $faqs = json_encode($response->viewData('page')['props']['faqs']);

        // The retired worked example, and the framing that went with it.
        $this->assertStringNotContainsString('5 PTW Access', $faqs);
        $this->assertStringContainsString('Operating Unit', $faqs);
    }

    /**
     * v2.59.0 -- the landing page's product showcase must present the
     * PLATFORM, not one department. The section it replaced rendered two
     * panels and both were HSE/PTW, which is a positioning error on a page
     * selling an Industrial Operations Platform.
     *
     * Asserted against the shipped source rather than a rendered page: the
     * showcase is a client component, and what matters is that the module
     * set stays broad if somebody edits it later.
     */
    public function test_the_product_showcase_presents_more_than_one_department(): void
    {
        $showcase = file_get_contents(base_path('resources/js/Components/public/PlatformShowcase.jsx'));

        foreach (['Dashboard', 'Health, Safety & Environment', 'Warehouse', 'Procurement', 'Maintenance'] as $module) {
            $this->assertStringContainsString($module, $showcase, "The showcase no longer presents {$module}.");
        }

        // Real IOMS vocabulary, so the marketing surface and the product agree.
        foreach (['Operating Units', 'Permit To Work', 'Purchase Order', 'Work Order'] as $term) {
            $this->assertStringContainsString($term, $showcase);
        }
    }

    /** The placeholder furniture the showcase replaced must not come back. */
    public function test_the_landing_page_shows_no_placeholder_product_mockups(): void
    {
        $welcome = file_get_contents(base_path('resources/js/Pages/Public/Welcome.jsx'));

        $this->assertStringNotContainsString("value: '—'", $welcome, 'Placeholder em-dash figures are back on the landing page.');
        $this->assertStringNotContainsString('PTW-2026-XXXXX', $welcome, 'A placeholder permit number is back on the landing page.');
    }

    /**
     * Motion must never be able to hide content. Every reveal is a one-shot
     * keyframe behind `motion-safe:`, so a visitor who prefers reduced
     * motion — or whose observer never fires — still sees everything.
     */
    public function test_the_reveal_system_cannot_hide_content(): void
    {
        $reveal = file_get_contents(base_path('resources/js/Components/public/Reveal.jsx'));

        $this->assertStringContainsString('motion-safe:animate-reveal', $reveal);
        $this->assertStringNotContainsString('opacity-0', $reveal, 'A reveal that starts at opacity 0 can strand content.');
    }
    public function test_get_started_is_an_onboarding_flow_and_not_a_login_redirect(): void
    {
        $this->package();

        $this->get(route('get-started'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Public/GetStarted')->has('plans'));
    }
}
