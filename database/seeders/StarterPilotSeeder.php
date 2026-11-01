<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Module;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * v2.139.0 -- A STARTER ORGANISATION FOR THE DARK THEME PILOT (ADR 082).
 *
 * The dark theme is offered to Starter tenants only while it is reviewed,
 * and every seeded tenant is on Enterprise, so a reviewer had no Starter
 * account to look through. This creates one, shaped the way
 * `TenantProvisioningService::provision()` shapes a real tenant (tenant,
 * company, subscription, the PACKAGE's own workspace and module grants),
 * with the two people the pilot is reviewed as:
 *
 *   starter.admin@ioms.local   Company Admin (super_admin inside the tenant)
 *   starter.hse@ioms.local     HSE user, a Department User of `hse`
 *
 * and a small operation to look at -- departments, eight employees, safety
 * equipment in each standing (valid, due soon, overdue), permits in three
 * states -- built by DemoTenantSeeder's OWN builders, called on this
 * tenant's company, so a reviewer sees the same records a prospect sees in
 * the Sandbox rather than a second, drifting set of fixtures.
 *
 * Test data only. It refuses to run in production, and it never sets a
 * password of its own: the password comes from IOMS_PILOT_PASSWORD (staging.sh
 * passes the staging app password it already generated), and without one
 * the accounts get the same throwaway 'password' every other seeded account
 * starts with. Idempotent: running it again re-asserts the plan and grants.
 *
 *   php artisan db:seed --class=StarterPilotSeeder
 */
class StarterPilotSeeder extends Seeder
{
    public const TENANT_SLUG = 'starter-pilot';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('StarterPilotSeeder creates test accounts and does not run in production.');
        }

        $package = Package::where('slug', 'starter')->first();

        if (! $package) {
            throw new RuntimeException('No `starter` package exists; run PackageSeeder first.');
        }

        $tenant = Tenant::firstOrCreate(
            ['slug' => self::TENANT_SLUG],
            ['name' => 'Starter Pilot (Test)', 'status' => Tenant::STATUS_ACTIVE]
        );

        $company = Company::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => 'STRPLT'],
            ['name' => 'Starter Pilot (Test)', 'is_active' => true]
        );

        Subscription::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'package_id' => $package->id,
                'type' => Subscription::TYPE_SUBSCRIPTION,
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => Subscription::CYCLE_YEARLY,
                'billing_mode' => Subscription::BILLING_MODE_MANUAL,
                'starts_at' => now(),
                'ends_at' => now()->addYear(),
                'agreed_price_monthly' => $package->price_monthly,
                'agreed_price_yearly' => $package->price_yearly,
                'agreed_currency' => $package->currency,
                'notes' => 'Dark theme pilot test tenant (StarterPilotSeeder).',
            ]
        );

        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        $tenant->modules()->sync(Module::whereIn('key', $package->defaultModuleKeys())->pluck('id'));

        $password = (string) (env('IOMS_PILOT_PASSWORD') ?: 'password');

        $previous = app(CurrentTenant::class)->get();
        app(CurrentTenant::class)->set($tenant);

        try {
            $this->account($tenant, $company, 'starter.admin@ioms.local', 'Starter Admin', User::ROLE_SUPER_ADMIN, null, $password);
            $hse = $this->account($tenant, $company, 'starter.hse@ioms.local', 'Starter HSE Officer', User::ROLE_HSE, 'hse', $password);

            // DemoTenantSeeder's builders are private and idempotent on a
            // natural key; Closure::call runs them against this company
            // without copying them or widening their visibility.
            (function () use ($company, $hse) {
                [$departments, $positions] = $this->structure($company);
                $employees = $this->employees($company, $departments, $positions);
                $projects = $this->projects($company, $hse);
                $this->equipment($company);
                $this->permits($company, $hse, $employees, $projects);
            })->call(new DemoTenantSeeder);
        } finally {
            app(CurrentTenant::class)->set($previous);
        }
    }

    private function account(Tenant $tenant, Company $company, string $email, string $name, string $role, ?string $department, string $password): User
    {
        $user = User::withoutGlobalScopes()->firstOrNew(['email' => $email]);

        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'role' => $role,
            'department_key' => $department,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'ptw_access' => $role === User::ROLE_HSE || (bool) $user->ptw_access,
        ])->save();

        return $user;
    }
}
