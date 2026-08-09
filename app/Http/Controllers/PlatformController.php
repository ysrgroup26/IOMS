<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\Package;
use App\Models\Scopes\TenantScope;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 2 (Platform Super Admin UI, Task #44). The `/platform/*`
 * surface -- reachable only by a Platform Super Admin (`role:platform_admin`,
 * see routes/web.php), operating on Tenant/Package/Subscription records
 * at the platform level. Deliberately NOT built on AuthenticatedLayout --
 * that layout's sidebar/department switcher/Work Center are all
 * tenant-scoped concepts a platform admin (no tenant, see
 * User::isPlatformAdmin()) has no meaningful relationship to. See
 * docs/ADR/008-tenancy-foundation.md.
 */
class PlatformController extends Controller
{
    /**
     * Every `withCount(['companies' => ...])` below explicitly bypasses
     * `App\Models\Scopes\TenantScope` -- that scope fails closed for a
     * Platform Super Admin (no tenant_id, see User::isPlatformAdmin())
     * exactly as designed for TENANT-side pages, but this controller is
     * cross-tenant BY DESIGN (route-gated to role:platform_admin only) --
     * without the bypass, every tenant would show "0 companies" here
     * regardless of how many it actually has. Caught and fixed during
     * this same milestone's own verification pass, see
     * docs/ADR/008-tenancy-foundation.md.
     */
    public function dashboard(): Response
    {
        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'tenants_total' => Tenant::count(),
                'tenants_active' => Tenant::where('status', Tenant::STATUS_ACTIVE)->count(),
                'tenants_trial' => Tenant::where('status', Tenant::STATUS_TRIAL)->count(),
                'tenants_suspended' => Tenant::where('status', Tenant::STATUS_SUSPENDED)->count(),
                'packages_total' => Package::count(),
                'subscriptions_active' => Subscription::where('status', Subscription::STATUS_ACTIVE)->count(),
            ],
            'recent_tenants' => Tenant::withCount(['companies' => fn ($q) => $q->withoutGlobalScope(TenantScope::class), 'users'])
                ->latest()
                ->take(5)
                ->get(['id', 'name', 'slug', 'status', 'created_at']),
        ]);
    }

    public function tenants(): Response
    {
        return Inertia::render('Platform/Tenants', [
            'tenants' => Tenant::withCount(['companies' => fn ($q) => $q->withoutGlobalScope(TenantScope::class), 'users'])
                ->with(['subscription.package:id,name'])
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'status', 'trial_ends_at', 'created_at']),
            'packages' => Package::active()->orderBy('sort_order')->get(['id', 'name', 'slug']),
        ]);
    }

    /**
     * Master -> Tenant Management. Creates a new customer organization.
     * Package is a form field, but is NOT a tenants-table column --
     * package assignment lives on Subscription (Tenant::subscription(),
     * hasOne latestOfMany), matching how SubscriptionSeeder already
     * anticipated this exact feature ("a brand new tenant created later
     * ... would choose its own package during onboarding instead of
     * going through this seeder"). Defaults to a monthly cycle starting
     * now; the Master can change the package again later via
     * updateTenant(), and billing-cycle/renewal management beyond that
     * is out of this feature's scope.
     */
    public function storeTenant(StoreTenantRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'status' => $validated['status'],
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'package_id' => $validated['package_id'],
            'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
        ]);

        ActivityLog::record('created', "Tenant \"{$tenant->name}\" was created.");

        return back()->with('success', 'Tenant created.');
    }

    /**
     * Master -> Tenant Management. Edits an existing tenant's name, slug,
     * status, and package IN PLACE -- `Tenant::update()`, never a
     * create-then-delete. The tenant's `id` never changes, so every
     * relationship keyed on it (companies, users, module/workspace
     * grants, subscription history) stays attached automatically; this
     * is also the mechanism by which the seeded "Default Tenant" gets
     * renamed on this production install -- it is not special-cased
     * anywhere, it is just a Tenant row like any other, editable the same
     * way as one created through storeTenant() above.
     *
     * Package changes update the tenant's current (latest) Subscription
     * row rather than creating a new one on every edit -- only a
     * genuinely new subscription period (a real plan change with its own
     * billing cycle) should add a new history row, and this UI doesn't
     * expose that distinction yet, so touching the existing latest row is
     * the conservative choice. A tenant with no Subscription row at all
     * (shouldn't normally happen post-storeTenant(), but defensive
     * against older/manually-created tenants) gets one created here
     * instead of silently no-op'ing.
     */
    public function updateTenant(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validated();

        $tenant->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'status' => $validated['status'],
        ]);

        $subscription = $tenant->subscription;

        if ($subscription) {
            if ((int) $subscription->package_id !== (int) $validated['package_id']) {
                $subscription->update(['package_id' => $validated['package_id']]);
            }
        } else {
            Subscription::create([
                'tenant_id' => $tenant->id,
                'package_id' => $validated['package_id'],
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => Subscription::CYCLE_MONTHLY,
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
            ]);
        }

        ActivityLog::record('updated', "Tenant \"{$tenant->name}\" details were updated.");

        return back()->with('success', 'Tenant updated.');
    }

    /**
     * Suspend/reactivate a tenant -- the coarse platform-level kill switch.
     * Does NOT touch `companies.tenant_id` or delete any data; a
     * suspended tenant's users can still authenticate (role/module checks
     * are unrelated), but nothing currently reads `Tenant::isActive()` to
     * block access yet -- see the Consequences note this leaves for a
     * later pass, matching this milestone's "structure now, enforcement
     * wiring can follow" pattern already used for Package/Subscription.
     */
    public function updateTenantStatus(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Tenant::STATUS_TRIAL, Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED, Tenant::STATUS_EXPIRED,
            ])],
        ]);

        $tenant->update(['status' => $validated['status']]);

        ActivityLog::record('updated', "Tenant \"{$tenant->name}\" status changed to {$validated['status']}.");

        return back()->with('success', 'Tenant status updated.');
    }

    /**
     * Milestone 3 (UAT #4/#5). The actual grant-management surface: which
     * modules/workspaces THIS tenant may use at all -- the ceiling a
     * Company Admin's own Settings > Module Visibility / Department
     * Navigation pages operate under (see SettingsController::updateModules()/
     * updateWorkspaces()). Not tenant-scoped data itself (Module/Workspace
     * are platform catalogs), so no TenantScope bypass needed here.
     */
    public function tenantGrants(Tenant $tenant): Response
    {
        $grantedModuleIds = $tenant->modules()->pluck('modules.id')->all();
        $grantedWorkspaceIds = $tenant->workspaces()->pluck('workspaces.id')->all();

        return Inertia::render('Platform/TenantGrants', [
            'tenant' => $tenant->only(['id', 'name', 'slug']),
            'modules' => Module::query()->orderBy('sort_order')->get(['id', 'key', 'label'])
                ->map(fn (Module $m) => ['id' => $m->id, 'key' => $m->key, 'label' => $m->label, 'granted' => in_array($m->id, $grantedModuleIds, true)]),
            'workspaces' => Workspace::query()->orderBy('sort_order')->get(['id', 'key', 'label', 'tier'])
                ->map(fn (Workspace $w) => ['id' => $w->id, 'key' => $w->key, 'label' => $w->label, 'tier' => $w->tier, 'granted' => in_array($w->id, $grantedWorkspaceIds, true)]),
        ]);
    }

    public function updateTenantGrants(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'module_ids' => ['array'],
            'module_ids.*' => ['integer', 'exists:modules,id'],
            'workspace_ids' => ['array'],
            'workspace_ids.*' => ['integer', 'exists:workspaces,id'],
        ]);

        $tenant->modules()->sync($validated['module_ids'] ?? []);
        $tenant->workspaces()->sync($validated['workspace_ids'] ?? []);

        ActivityLog::record('updated', "Tenant \"{$tenant->name}\" module/workspace grants were updated.");

        return back()->with('success', 'Grants updated.');
    }
}
