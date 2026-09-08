<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Package;
use App\Models\TenantRegistration;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ApprovedCatalogue;
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
 *
 * v2.60.0 -- TWO CHANGES.
 *
 * The figures moved to Tests\Support\ApprovedCatalogue, because they were
 * transcribed into three test files and repricing broke all three.
 *
 * And this class no longer builds its own catalogue. The four-tier
 * migration writes the plans into `packages`, so a RefreshDatabase run
 * already has them -- creating a second Starter used to be harmless and is
 * now a unique-key violation. Reading the migrated rows is also the
 * stronger test: it asserts what a deployment actually ends up with, not
 * what a test fixture chose to type.
 */
class PricingConsistencyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: float, 2: float, 3: ?int, 4: ?int}> */
    public static function approvedPlans(): array
    {
        return ApprovedCatalogue::provider();
    }

    /* ================================================================
     * 1. THE APPROVED PRICES
     * ================================================================ */

    #[\PHPUnit\Framework\Attributes\DataProvider('approvedPlans')]
    public function test_the_migrated_catalog_carries_the_approved_prices(
        string $slug,
        float $monthly,
        float $yearly,
        ?int $users,
        ?int $units,
    ): void {
        $package = Package::where('slug', $slug)->first();

        $this->assertNotNull($package, "The catalog is missing the {$slug} plan.");
        $this->assertEquals($monthly, (float) $package->price_monthly, "$slug monthly price drifted.");
        $this->assertEquals($yearly, (float) $package->price_yearly, "$slug yearly price drifted.");
        $this->assertSame($users, $package->max_users, "$slug user capacity drifted.");
        $this->assertSame($units, $package->max_companies, "$slug operating unit capacity drifted.");
        $this->assertSame('IDR', $package->currency);
        $this->assertFalse((bool) $package->is_custom, "$slug is a standardized plan with a published price.");
        $this->assertTrue((bool) $package->is_public, "$slug must be offered publicly.");
    }

    /** Four tiers, in ladder order, and no stray fifth plan left behind by a migration. */
    public function test_the_public_catalog_is_exactly_the_four_approved_tiers(): void
    {
        $slugs = app(PricingService::class)->publicPlans()->pluck('slug')->all();

        $this->assertSame(ApprovedCatalogue::slugs(), $slugs);
    }

    /** Every tier costs more than the one below it, in both cycles. */
    public function test_the_ladder_only_ever_goes_up(): void
    {
        $plans = app(PricingService::class)->publicPlans();

        foreach ($plans->sliding(2) as $pair) {
            [$lower, $higher] = $pair->values()->all();

            $this->assertGreaterThan(
                $lower['monthly']['amount'],
                $higher['monthly']['amount'],
                "{$higher['slug']} must cost more per month than {$lower['slug']}."
            );
            $this->assertGreaterThan(
                $lower['yearly']['amount'],
                $higher['yearly']['amount'],
                "{$higher['slug']} must cost more per year than {$lower['slug']}."
            );
        }
    }

    /* ================================================================
     * 2. THE ANNUAL SAVING IS DERIVED, NOT ASSERTED
     * ================================================================ */

    /**
     * The worked example, in full: Professional at Rp799.000/month is
     * Rp9.588.000 over twelve months against an annual price of
     * Rp7.990.000 — a saving of Rp1.598.000, which is 16.67% and shown as
     * 17%.
     */
    public function test_the_annual_saving_matches_the_plans_own_two_prices(): void
    {
        $plans = app(PricingService::class)->publicPlans()->keyBy('slug');

        $professional = $plans['professional']['annual_saving'];

        $this->assertEquals(9588000.0, $professional['monthly_equivalent']);
        $this->assertEquals(1598000.0, $professional['amount']);
        $this->assertSame(17, $professional['percent']);
        $this->assertSame('Rp9.588.000', $professional['monthly_equivalent_formatted']);
        $this->assertSame('Rp1.598.000', $professional['formatted']);

        // The same arithmetic must hold for every plan, not just the one
        // in the worked example. Annual is monthly x 10 on every tier, so
        // every tier lands on the same 17%.
        foreach ($plans as $slug => $plan) {
            $saving = $plan['annual_saving'];

            $this->assertNotNull($saving, "$slug should present an annual saving.");
            $this->assertSame(17, $saving['percent'], "$slug should save the same 17% as every other tier.");
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
        $package = Package::create([
            'name' => 'Custom', 'slug' => 'custom',
            'price_monthly' => 0, 'price_yearly' => 0, 'currency' => 'IDR',
            'is_active' => true, 'is_public' => true, 'is_custom' => true,
        ]);

        $this->assertNull(app(PricingService::class)->summarize($package)['annual_saving']);
    }

    /** An annual price that is not actually cheaper must never be dressed up as a discount. */
    public function test_an_annual_price_that_saves_nothing_presents_no_saving(): void
    {
        $package = Package::create([
            'name' => 'Flat', 'slug' => 'flat',
            'price_monthly' => 100000, 'price_yearly' => 1200000, 'currency' => 'IDR',
            // is_custom stated explicitly: summarize() reads the in-memory
            // model, where an omitted boolean is null, not the column default.
            'is_active' => true, 'is_public' => true, 'is_custom' => false,
        ]);

        $this->assertNull(app(PricingService::class)->summarize($package)['annual_saving']);
    }

    /* ================================================================
     * 3. THE SAME NUMBER REACHES EVERY SURFACE
     * ================================================================ */

    public function test_landing_pricing_and_get_started_quote_the_same_amounts(): void
    {
        $fromPricing = collect($this->get(route('pricing'))->viewData('page')['props']['plans'])->keyBy('slug');
        $fromLanding = collect($this->get(route('home'))->viewData('page')['props']['plans'])->keyBy('slug');
        $fromGetStarted = collect($this->get(route('get-started'))->viewData('page')['props']['plans'])->keyBy('slug');

        foreach (ApprovedCatalogue::slugs() as $slug) {
            $this->assertArrayHasKey($slug, $fromLanding, "The landing page does not offer {$slug}.");
            $this->assertArrayHasKey($slug, $fromGetStarted, "Get Started does not offer {$slug}.");

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
        $pricing = app(PricingService::class);
        $package = Package::where('slug', 'business')->firstOrFail();

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

        $this->assertEquals(14990000.0, (float) $invoice->amount);
        $this->assertEquals((float) $package->price_yearly, (float) $invoice->amount);
        $this->assertSame('IDR', $invoice->currency);
    }

    /* ================================================================
     * 4. CAPACITY LANGUAGE
     * ================================================================ */

    /**
     * PTW Access was retired as a sold capacity in v2.53.0 and must not
     * return to a pricing payload.
     *
     * v2.60.0 narrowed this from "the word 'ptw' appears nowhere" to "no
     * FIELD describes a PTW allowance". Starter's description now names
     * Permit To Work among the HSE modules it includes, which is true and
     * is the point of the tier -- naming an included capability is not
     * selling a quota. The thing that must never come back is the number.
     */
    public function test_no_pricing_surface_exposes_a_ptw_quota(): void
    {
        foreach (app(PricingService::class)->publicPlans() as $plan) {
            $this->assertArrayNotHasKey('max_ptw_users', $plan, "{$plan['slug']} exposes a PTW seat allowance.");

            foreach (array_keys($plan) as $field) {
                $this->assertStringNotContainsStringIgnoringCase(
                    'ptw',
                    $field,
                    "{$plan['slug']} carries a PTW-shaped capacity field: {$field}."
                );
            }
        }
    }
}
