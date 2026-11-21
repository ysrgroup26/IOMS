<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Workspace;
use App\Services\PricingService;
use App\Services\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.144.0 -- THE ANNUAL BENEFIT IS A FIRST-YEAR OFFER (Founder, 21 Nov 2026).
 *
 * Business pays 12 and gets 13 months, Professional pays 11 and gets 12 --
 * on the subscription's FIRST yearly payment only. Every later year is a
 * normal year: twelve agreed monthly prices for twelve months.
 */
class FirstYearAnnualBenefitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\PackageSeeder::class);
    }

    private function subscription(string $slug, string $cycle, array $extra = []): Subscription
    {
        $package = Package::where('slug', $slug)->firstOrFail();
        $suffix = uniqid();
        $tenant = Tenant::create(['name' => 'C '.$suffix, 'slug' => 'c-'.$suffix, 'status' => Tenant::STATUS_ACTIVE]);
        Company::withoutGlobalScopes()->create(['name' => 'Yard', 'code' => strtoupper(substr($suffix, -6)), 'tenant_id' => $tenant->id, 'is_active' => true]);
        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));

        return Subscription::create(array_merge([
            'tenant_id' => $tenant->id, 'package_id' => $package->id, 'status' => Subscription::STATUS_ACTIVE,
            'type' => 'subscription', 'billing_cycle' => $cycle,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay(),
            'agreed_price_monthly' => $package->price_monthly, 'agreed_price_yearly' => $package->price_yearly,
            'agreed_currency' => $package->currency,
        ], $extra))->fresh();
    }

    public function test_business_first_yearly_payment_buys_fourteen_months_then_twelve(): void
    {
        $s = $this->subscription('business', Subscription::CYCLE_YEARLY);
        $this->assertTrue($s->annualPromoAvailable());
        $this->assertSame(14, $s->annualServiceMonths());

        $this->travelTo(now()->startOfDay());
        app(SubscriptionLifecycleService::class)->extendPeriod($s, Subscription::CYCLE_YEARLY);
        $s->refresh();
        $this->assertNotNull($s->annual_promo_used_at, 'The first yearly payment consumes the first-year offer.');
        $this->assertSame(14, (int) round(now()->diffInMonths($s->ends_at)));

        $endFirst = $s->ends_at->copy();
        $this->travelTo($endFirst->copy()->subDay());
        app(SubscriptionLifecycleService::class)->extendPeriod($s, Subscription::CYCLE_YEARLY);
        $s->refresh();
        $this->assertSame(12, (int) round($endFirst->diffInMonths($s->ends_at)), 'The second year is a normal twelve months.');
    }

    public function test_professional_second_year_is_priced_as_twelve_months(): void
    {
        $s = $this->subscription('professional', Subscription::CYCLE_YEARLY);
        $this->assertEqualsWithDelta(6660000, $s->agreedAmountFor(Subscription::CYCLE_YEARLY), 0.01);

        $s->forceFill(['annual_promo_used_at' => now()->subYear()])->save();
        $s->refresh();
        $this->assertEqualsWithDelta(555000 * 12, $s->agreedAmountFor(Subscription::CYCLE_YEARLY), 0.01);
        $this->assertSame(12.0, $s->annualPaidMonths());
        $this->assertSame(12, $s->annualServiceMonths());
    }

    public function test_a_plan_move_after_the_first_year_is_priced_as_a_normal_year(): void
    {
        $s = $this->subscription('starter', Subscription::CYCLE_YEARLY, ['annual_promo_used_at' => now()->subYear()]);
        $professional = Package::where('slug', 'professional')->firstOrFail();
        $amount = app(SubscriptionLifecycleService::class)->planAmount($s, $professional, Subscription::CYCLE_YEARLY);
        $this->assertEqualsWithDelta(555000 * 12, $amount, 0.01, 'The Professional discount must not return on a plan move.');
    }

    public function test_a_monthly_payment_does_not_use_the_first_year_offer(): void
    {
        $s = $this->subscription('business', Subscription::CYCLE_MONTHLY);
        app(SubscriptionLifecycleService::class)->extendPeriod($s, Subscription::CYCLE_MONTHLY);
        $this->assertNull($s->fresh()->annual_promo_used_at);
    }

    public function test_public_terms_say_first_year_and_enterprise_is_not_offered(): void
    {
        $plans = collect(app(PricingService::class)->publicPlans());
        $business = $plans->firstWhere('slug', 'business');
        $this->assertSame('Bayar 12 bulan, akses 14 bulan', $business['annual_terms']['label']);
        $this->assertTrue($business['annual_terms']['first_year_only']);
        $this->assertNull($plans->firstWhere('slug', 'enterprise'));
        $this->assertFalse((bool) Package::where('slug', 'enterprise')->value('is_active'));
    }
}
