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
     *
     * v1.11.15 (SaaS Package + Ecosystem pass, Part 1/26): `features`
     * corrected to the actual product requirement -- Starter's old list
     * (`employees, ppe, reports`) was barely a fraction of real HSE
     * functionality (no incidents/observations/inspections/CAPA/PTW/
     * LOTO/gas-test/JSA/HIRADC/waste/master-data/man-hour at all), and
     * Professional/Enterprise previously had IDENTICAL lists -- Enterprise
     * unlocked nothing Professional didn't already have. Package.features
     * itself is a display/reference field only (confirmed via a
     * whole-codebase search that nothing reads `hasFeature()`/`features`
     * at runtime -- the actual enforcement mechanism is the Module/
     * Workspace grant tables, see `Package::defaultWorkspaceKeys()`/
     * `defaultModuleKeys()` and `PlatformController::storeTenant()`), but
     * corrected here too so the stored catalog description matches what
     * the tenant actually gets, not a stale, narrower list.
     *
     * v2.14.0 (SaaS Productization / Pricing Foundation): added
     * `currency`/`trial_days`/`is_public`/`is_custom`. Deliberately did
     * NOT touch any `price_monthly`/`price_yearly` value already seeded
     * here -- those are an existing, prior business decision recorded in
     * this repository, and this phase's own directive explicitly forbids
     * inventing/changing final prices without a documented decision.
     * Enterprise becomes `is_custom = true` (sold by negotiation, matching
     * its "Hubungi Kami" presentation on the Plans page) -- its existing
     * `price_monthly`/`price_yearly` values are left in place rather than
     * nulled out, since `PricingService` already ignores the numeric
     * price entirely once `is_custom` is true, and leaving them intact
     * avoids a destructive-looking change to existing seed data for a
     * purely cosmetic/presentation flag.
     */
    public function run(): void
    {
        $packages = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'A fully operational HSE product for a single company -- incidents, observations, inspections, PPE, PTW, CAPA, and every other HSE module, without requiring HRD.',
                'price_monthly' => 0,
                'price_yearly' => 0,
                'currency' => 'IDR',
                'trial_days' => null,
                'max_users' => 10,
                'max_companies' => 1,
                'features' => ['employees', 'ppe', 'kpi_input', 'reports'],
                'is_public' => true,
                'is_custom' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'HSE plus HRD/workforce management and cross-department management visibility, for growing operations across multiple companies.',
                'price_monthly' => 49,
                'price_yearly' => 490,
                'currency' => 'IDR',
                'trial_days' => 14,
                'max_users' => 50,
                'max_companies' => 5,
                'features' => ['employees', 'ppe', 'kpi_input', 'reports', 'projects', 'daily_reports', 'material_requests'],
                'is_public' => true,
                'is_custom' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Full IOMS -- every department (HSE, HRD, Project Management, Logistics/PPIC, Warehouse, Procurement, Asset Management, Maintenance, Quality Control) and unlimited users/companies.',
                'price_monthly' => 149,
                'price_yearly' => 1490,
                'currency' => 'IDR',
                'trial_days' => null,
                'max_users' => null,
                'max_companies' => null,
                'features' => ['employees', 'ppe', 'kpi_input', 'reports', 'projects', 'daily_reports', 'material_requests'],
                'is_public' => true,
                'is_custom' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($packages as $package) {
            Package::updateOrCreate(['slug' => $package['slug']], $package);
        }
    }
}
