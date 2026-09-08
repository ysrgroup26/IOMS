<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Package;
use App\Models\TenantRegistration;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.56.0 -- ONE PRICE, EVERY SURFACE.
 *
 * The acquisition journey shows a price five times: landing, Pricing, Get
 * Started, the checkout order summary, and the invoice. They must all be
 * the same number, and they must all come from the `packages` table rather
 * than from a constant somebody typed twice.
 *
 * These tests also pin the approved commercial figures. IOMS has changed
 * its pricing several times during development and older numbers keep
 * resurfacing in discussion; the current approved set is asserted here so a
 * revert is a failing test rather than a live pricing error.
 */
class PricingConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /** The approved commercial model. Monthly, yearly, users, operating units. */
    public static function approvedPlans(): array
    {
        return [
            'starter' => ['starter', 299000.0, 2990000.0, 10, 1],
            'professional' => ['professional', 999000.0, 9990000.0, 50, 2],
            'enterprise' => ['enterprise', 1999000.0, 19990000.0, null, null],
        ];
    }

    private function seedApprovedPackages(): void
    {
        $sort = 0;

        foreach (self::approvedPlans() as [$slug, $monthly, $yearly, $users, $units]) {
            Package::create([
                'name' => ucfirst($slug), 'slug' => $slug,
                'price_monthly' => $monthly, 'price_yearly' => $yearly, 'currency' => 'IDR',
                'max_users' => $users, 'max_companies' => $units,
                'is_active' => true, 'is_public' => true, 'sort_order' => $sort++,
            ]);
        }
    }

    /* ================================================================
     * 1. THE APPROVED PRICES
     * ================================================================ */

    public function test_the_seeded_catalog_carries_the_approved_prices(): void
    {
        $this->seedApprovedPackages();

        foreach (self::approvedPlans() as [$slug, $monthly, $yearly, $users, $units]) {
            $package = Package::where('slug', $slug)->firstOrFail();

            $this->assertEquals($monthly, (float) $package->price_monthly, "$slug monthly price drifted.");
            $this->assertEquals($yearly, (float) $package->price_yearly, "$slug yearly price drifted.");
            $this->assertSame($users, $package->max_users, "$slug user capacity drifted.");
            $this->assertSame($units, $package->max_companies, "$slug operating unit capacity drifted.");
        }
    }

    /* ================================================================
     * 2. THE ANNUAL SAVING IS DERIVED, NOT ASSERTED
     * ================================================================ */

    /**
     * The worked example, in full: Professional at Rp999.000/month is
     * Rp11.988.000 over twelve months against an annual price of
     * Rp9.990.000 — a saving of Rp1.998.000, which is 16.67% and shown as
     * 17%.
     */
    public function test_the_annual_saving_matches_the_plans_own_two_prices(): void
    {
        $this->seedApprovedPackages();

        $plans = app(PricingService::class)->publicPlans()->keyBy('slug');

        $professional = $plans['professional']['annual_saving'];

        $this->assertEquals(11988000.0, $professional['monthly_equivalent']);
        $this->assertEquals(1998000.0, $professional['amount']);
        $this->assertSame(17, $professional['percent']);
        $this->assertSame('Rp11.988.000', $professional['monthly_equivalent_formatted']);
        $this->assertSame('Rp1.998.000', $professional['formatted']);

        // The same arithmetic must hold for every plan, not just the one
        // in the worked example.
        foreach ($plans as $slug => $plan) {
            $saving = $plan['annual_saving'];

            $this->assertNotNull($saving, "$slug should present an annual saving.");
            $this->assertEquals(
                $plan['monthly']['amount'] * 12,
                $saving['monthly_equivalent'],
                "$slug monthly equivalent is not twelve monthly payments."
            );
            $this->assertEquals(
                $saving['monthly_equivalent'] - $plan['yearly']['amount'],
                $saving['amount'],
                "$slug saving is not the difference between the two prices."
            );
        }
    }

    /**
     * A plan that is custom or free must present no saving rather than a
     * zero. (`price_monthly` is NOT NULL on this table, so "no price" is
     * stored as 0 — which is exactly the case that must not divide.)
     */
    public function test_a_plan_without_two_real_prices_presents_no_saving(): void
    {
        Package::create([
            'name' => 'Custom', 'slug' => 'custom',
            'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'IDR',
            'is_active' => true, 'is_public' => true, 'is_custom' => true,
        ]);

        $plan = app(PricingService::class)->publicPlans()->firstWhere('slug', 'custom');

        $this->assertNull($plan['annual_saving']);
    }

    /** An annual price that is not actually cheaper must never be dressed up as a discount. */
    public function test_an_annual_price_that_saves_nothing_presents_no_saving(): void
    {
        Package::create([
            'name' => 'Flat', 'slug' => 'flat',
            'price_monthly' => 100000, 'price_yearly' => 1200000, 'currency' => 'IDR',
            'is_active' => true, 'is_public' => true,
        ]);

        $plan = app(PricingService::class)->publicPlans()->firstWhere('slug', 'flat');

        $this->assertNull($plan['annual_saving']);
    }

    /* ================================================================
     * 3. THE SAME NUMBER REACHES EVERY SURFACE
     * ================================================================ */

    public function test_landing_pricing_and_get_started_quote_the_same_amounts(): void
    {
        $this->seedApprovedPackages();

        $fromPricing = collect($this->get(route('pricing'))->viewData('page')['props']['plans'])->keyBy('slug');
        $fromLanding = collect($this->get(route('home'))->viewData('page')['props']['plans'])->keyBy('slug');
        $fromGetStarted = collect($this->get(route('get-started'))->viewData('page')['props']['plans'])->keyBy('slug');

        foreach (array_keys(self::approvedPlans()) as $slug) {
            $this->assertSame(
                $fromPricing[$slug]['monthly']['formatted'],
                $fromLanding[$slug]['monthly']['formatted'],
                "$slug monthly price differs between Pricing and the landing page."
            );
            $this->assertSame(
                $fromPricing[$slug]['yearly']['formatted'],
                $fromGetStarted[$slug]['yearly']['formatted'],
                "$slug annual price differs between Pricing and Get Started."
            );
            $this->assertSame(
                $fromPricing[$slug]['annual_saving'],
                $fromGetStarted[$slug]['annual_saving'],
                "$slug annual saving differs between Pricing and Get Started."
            );
        }
    }

    /**
     * The last link in the chain: what the customer was quoted has to be
     * what the invoice bills. `amountFor()` is the one server-side answer
     * to "what does this plan cost on this cycle", and checkout uses it.
     */
    public function test_the_invoice_amount_matches_the_quoted_plan_price(): void
    {
        $this->seedApprovedPackages();

        $pricing = app(PricingService::class);
        $package = Package::where('slug', 'professional')->firstOrFail();

        $registration = TenantRegistration::create([
            'token' => TenantRegistration::newToken(),
            'reference' => TenantRegistration::newReference(),
            'status' => TenantRegistration::STATUS_VERIFIED,
            'contact_name' => 'Budi', 'contact_email' => 'budi@contoh.test',
            'password' => bcrypt('secret-pass-1'),
            'company_legal_name' => 'PT Contoh', 'company_address' => 'Jl. 1',
            'company_city' => 'Batam', 'company_province' => 'Kepri',
            'package_id' => $package->id, 'billing_cycle' => 'yearly',
            'amount' => $pricing->amountFor($package, 'yearly'), 'currency' => 'IDR',
            'email_verified_at' => now(),
        ]);

        // Checkout issues the invoice from the catalog, never from the request.
        $this->post(route('register.checkout', $registration->token))->assertRedirect();

        $invoice = Invoice::where('registration_id', $registration->id)->firstOrFail();

        $this->assertEquals(9990000.0, (float) $invoice->amount);
        $this->assertEquals((float) $package->price_yearly, (float) $invoice->amount);
        $this->assertSame('IDR', $invoice->currency);
    }

    /* ================================================================
     * 4. CAPACITY LANGUAGE
     * ================================================================ */

    /** PTW Access was retired as a sold capacity in v2.53.0 and must not return to a pricing payload. */
    public function test_no_pricing_surface_exposes_a_ptw_quota(): void
    {
        $this->seedApprovedPackages();

        $payload = json_encode(app(PricingService::class)->publicPlans()->all());

        $this->assertStringNotContainsString('max_ptw_users', $payload);
        $this->assertStringNotContainsString('ptw', strtolower($payload));
    }
}
