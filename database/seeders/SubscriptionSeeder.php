<?php

namespace Database\Seeders;

use App\Models\Package;
use App\Models\Subscription;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    /**
     * Milestone 2 (Package + Subscription). Gives the current tenant
     * (bound by DatabaseSeeder before any seeder runs -- see its own doc
     * comment) an active Enterprise subscription, so an existing
     * installation isn't left with no subscription row at all. A brand
     * new tenant created later (Task #44's Platform Super Admin UI) would
     * choose its own package during onboarding instead of going through
     * this seeder.
     */
    public function run(): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        if (! $tenantId) {
            return;
        }

        $package = Package::where('slug', 'enterprise')->first();

        if (! $package) {
            return;
        }

        Subscription::firstOrCreate(
            ['tenant_id' => $tenantId, 'package_id' => $package->id],
            [
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => Subscription::CYCLE_YEARLY,
                'starts_at' => now(),
                'ends_at' => now()->addYear(),
            ]
        );
    }
}
