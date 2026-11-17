<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CI-17 -- "active subscriptions" is derived from the dates, not the stored
 * status: three stored-active subscriptions that had all lapsed were counted
 * as active on Master Admin and in the Agent API.
 */
class SubscriptionLifecycleCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lapsed_subscription_with_a_stored_active_status_is_not_counted_active(): void
    {
        $package = Package::create(['name' => 'Starter', 'slug' => 'starter-'.uniqid(), 'is_active' => true,
            'is_public' => true, 'price_monthly' => 239000, 'price_yearly' => 2390000, 'currency' => 'IDR']);

        foreach (['now' => now()->addDays(20), 'grace' => now()->subDays(2), 'lapsed' => now()->subDays(40)] as $slug => $ends) {
            $tenant = Tenant::create(['name' => "T {$slug}", 'slug' => "t-{$slug}", 'status' => Tenant::STATUS_ACTIVE]);
            Subscription::create(['tenant_id' => $tenant->id, 'package_id' => $package->id,
                'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
                'billing_cycle' => Subscription::CYCLE_MONTHLY, 'starts_at' => now()->subMonths(2), 'ends_at' => $ends]);
        }

        $counts = Subscription::lifecycleCounts();

        $this->assertSame(1, $counts[Subscription::LIFECYCLE_ACTIVE]);
        $this->assertSame(1, $counts[Subscription::LIFECYCLE_GRACE]);
        $this->assertSame(1, $counts[Subscription::LIFECYCLE_LAPSED]);
    }
}
