<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    /**
     * Milestone 2 (Package + Subscription). Default catalog so the
     * Subscription structure has real rows to point at immediately --
     * not tenant-scoped, no CurrentTenant dependency (see Package's own
     * doc comment).
     */
    public function run(): void
    {
        $packages = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For a single company getting started with core HR and HSE tracking.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'max_users' => 10,
                'max_companies' => 1,
                'features' => ['employees', 'ppe', 'reports'],
                'sort_order' => 1,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'For growing operations across multiple companies and departments.',
                'price_monthly' => 49,
                'price_yearly' => 490,
                'max_users' => 50,
                'max_companies' => 5,
                'features' => ['employees', 'ppe', 'reports', 'projects', 'daily_reports', 'material_requests', 'kpi_input'],
                'sort_order' => 2,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Unlimited users and companies, full module access.',
                'price_monthly' => 149,
                'price_yearly' => 1490,
                'max_users' => null,
                'max_companies' => null,
                'features' => ['employees', 'ppe', 'reports', 'projects', 'daily_reports', 'material_requests', 'kpi_input'],
                'sort_order' => 3,
            ],
        ];

        foreach ($packages as $package) {
            Package::updateOrCreate(['slug' => $package['slug']], $package);
        }
    }
}
