<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\Package;
use App\Models\Scopes\TenantScope;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
     * Master -> Tenant Management. Creates a new customer organization
     * end-to-end: the Tenant, its first Subscription, AND its first
     * Administrator user -- a tenant nobody can log into isn't actually
     * usable, so this is one atomic operation, not "create tenant, then
     * remember to add a user later." Wrapped in a DB transaction: if
     * Administrator creation fails (e.g. a race on the email-unique
     * check), the Tenant/Subscription rows roll back too rather than
     * leaving an orphaned, login-less tenant behind.
     *
     * Package is a form field, but is NOT a tenants-table column --
     * package assignment lives on Subscription (Tenant::subscription(),
     * hasOne latestOfMany), matching how SubscriptionSeeder already
     * anticipated this exact feature ("a brand new tenant created later
     * ... would choose its own package during onboarding instead of
     * going through this seeder"). Defaults to a monthly cycle starting
     * now; the Master can change the package again later via
     * updateTenant(), and billing-cycle/renewal management beyond that
     * is out of this feature's scope.
     *
     * The Administrator is created exactly the way
     * SettingsController::storeUser() already creates a tenant's own
     * users (role column set to super_admin, tenant_id explicit, password
     * hashed) -- this is the codebase's existing live authorization path
     * (see CLAUDE.md's Authorization note), not a new one invented here.
     */
    public function storeTenant(StoreTenantRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated) {
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

            // v1.11.15 (SaaS Package + Ecosystem pass, Part 1/26/27):
            // previously the chosen Package had NO effect on what this
            // tenant could actually reach -- storeTenant() never granted
            // any Module/Workspace row at all, and a Platform Admin had
            // to remember to visit Tenant Grants separately afterward.
            // Package::defaultWorkspaceKeys()/defaultModuleKeys() is the
            // canonical Starter=HSE/Professional=HSE+HRD/Enterprise=all
            // mapping this pass defines; applied here so a new tenant is
            // usable immediately, matching what its Subscription actually
            // says it bought. A Platform Admin can still hand-adjust
            // individual grants afterward via the existing Tenant Grants
            // page -- this only sets sensible defaults, it doesn't remove
            // that page's own purpose.
            $package = Package::find($validated['package_id']);
            if ($package) {
                $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
                $tenant->modules()->sync(Module::whereIn('key', $package->defaultModuleKeys())->pluck('id'));
            }

            User::create([
                'name' => $validated['admin_name'],
                'email' => $validated['admin_email'],
                'password' => Hash::make($validated['admin_password']),
                'role' => User::ROLE_SUPER_ADMIN,
                'tenant_id' => $tenant->id,
                'is_active' => true,
            ]);

            ActivityLog::record('created', "Tenant \"{$tenant->name}\" was created, with Administrator {$validated['admin_email']}.");
        });

        return back()->with('success', 'Tenant and its Administrator account were created.');
    }

    /**
     * Master -> Tenant Management. The Master-side Tenant Detail view --
     * real data only (companies/users counts via the same TenantScope
     * bypass every other cross-tenant query in this controller already
     * uses, actual Subscription/Package, the actual first Administrator
     * row), never a placeholder. This is a READ view for the Master, not
     * a way to become that tenant's user -- it does not touch the
     * Master's own session/tenant_id/role in any way (see
     * User::isPlatformAdmin(), unchanged by this method).
     */
    public function show(Tenant $tenant): Response
    {
        $tenant->loadCount(['companies' => fn ($q) => $q->withoutGlobalScope(TenantScope::class), 'users'])
            ->load(['subscription.package']);

        // The tenant's own Administrator -- oldest super_admin account
        // belongs to this tenant is, in practice, the Initial
        // Administrator created alongside the tenant by storeTenant()
        // above. `User` carries no global scope (only `Company` does --
        // see App\Models\Scopes\TenantScope's own doc comment), so a
        // plain tenant_id filter is correct here, no bypass needed.
        $administrator = User::where('tenant_id', $tenant->id)
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->oldest()
            ->first(['id', 'name', 'email', 'is_active', 'created_at']);

        return Inertia::render('Platform/TenantDetail', [
            'tenant' => $tenant->only(['id', 'name', 'slug', 'status', 'trial_ends_at', 'created_at', 'updated_at', 'companies_count', 'users_count']),
            'subscription' => $tenant->subscription ? [
                'id' => $tenant->subscription->id,
                'package_id' => $tenant->subscription->package_id,
                'package_name' => $tenant->subscription->package?->name,
                'type' => $tenant->subscription->type,
                'status' => $tenant->subscription->status,
                'billing_cycle' => $tenant->subscription->billing_cycle,
                'seat_limit' => $tenant->subscription->seatLimit(),
                'license_key' => $tenant->subscription->license_key,
                'billing_reference' => $tenant->subscription->billing_reference,
                'starts_at' => $tenant->subscription->starts_at,
                'ends_at' => $tenant->subscription->ends_at,
                'trial_ends_at' => $tenant->subscription->trial_ends_at,
                'notes' => $tenant->subscription->notes,
                'is_usable' => $tenant->subscription->isUsable(),
            ] : null,
            'administrator' => $administrator,
            'packages' => Package::active()->orderBy('sort_order')->get(['id', 'name', 'slug']),
            'subscriptionTypes' => Subscription::TYPES,
            'subscriptionStatuses' => Subscription::STATUSES,
            'invoices' => Invoice::where('tenant_id', $tenant->id)->latest()->get(['id', 'invoice_number', 'amount', 'currency', 'status', 'due_date', 'payment_date', 'created_at']),
        ]);
    }

    /**
     * v1.11.0 (SaaS Finalization Pass, Part 10). Updates the tenant's
     * CURRENT (latest) Subscription row in place -- same "edit in place,
     * only a genuine plan change creates a new history row" convention
     * updateTenant() already established for package_id. `ends_at`/
     * `trial_ends_at` are cleared server-side whenever `type` is set to
     * lifetime, regardless of what the form submitted, so a lifetime
     * record can never carry a stale/misleading expiry date.
     */
    public function updateSubscription(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
            'type' => ['required', Rule::in(Subscription::TYPES)],
            'status' => ['required', Rule::in(Subscription::STATUSES)],
            'billing_cycle' => ['required', Rule::in([Subscription::CYCLE_MONTHLY, Subscription::CYCLE_YEARLY])],
            'seat_limit' => ['nullable', 'integer', 'min:1'],
            'license_key' => ['nullable', 'string', 'max:255'],
            'billing_reference' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'trial_ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validated['type'] === Subscription::TYPE_LIFETIME) {
            $validated['ends_at'] = null;
            $validated['trial_ends_at'] = null;
        } elseif ($validated['status'] === Subscription::STATUS_TRIAL && empty($validated['trial_ends_at'])) {
            // v2.14.0 (SaaS Productization, Part 6): the missing trial
            // foundation piece -- a trial status previously had no way to
            // derive its own end date from the chosen Package's
            // `trial_days` unless a Platform Admin filled in
            // `trial_ends_at` by hand. Only fills a BLANK field; an
            // explicitly-entered date is always respected as-is.
            $package = Package::find($validated['package_id']);
            if ($package?->trial_days) {
                $validated['trial_ends_at'] = now()->addDays($package->trial_days);
            }
        }

        $subscription = $tenant->subscription;

        if ($subscription) {
            $subscription->update([...$validated, 'created_by' => $subscription->created_by ?? $request->user()->id]);
        } else {
            Subscription::create([...$validated, 'tenant_id' => $tenant->id, 'created_by' => $request->user()->id]);
        }

        ActivityLog::record('updated', "Tenant \"{$tenant->name}\" subscription/license updated ({$validated['type']}, {$validated['status']}).");

        return back()->with('success', 'Subscription updated.');
    }

    /** v1.11.0, Part 16. Manual invoice creation -- no payment gateway exists, this is the admin-recorded billing document itself. */
    public function storeInvoice(Request $request, Tenant $tenant): RedirectResponse
    {
        $validated = $request->validate([
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:3'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        Invoice::create([
            ...$validated,
            'invoice_number' => Invoice::generateNumber($tenant->id),
            'tenant_id' => $tenant->id,
            'subscription_id' => $tenant->subscription?->id,
            'status' => Invoice::STATUS_ISSUED,
            'created_by' => $request->user()->id,
        ]);

        ActivityLog::record('created', "Invoice issued for tenant \"{$tenant->name}\".");

        return back()->with('success', 'Invoice created.');
    }

    /** v1.11.0, Part 16. The ONLY place `status` can become 'paid' -- always an explicit admin action recording a payment that happened outside this system, never inferred. */
    public function markInvoicePaid(Request $request, Invoice $invoice): RedirectResponse
    {
        $validated = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['nullable', 'string', 'max:100'],
        ]);

        $invoice->markPaid($validated['payment_reference'] ?? null, $validated['payment_method'] ?? null);

        ActivityLog::record('updated', "Invoice {$invoice->invoice_number} marked paid.");

        return back()->with('success', 'Invoice marked as paid.');
    }

    /** v1.11.0, Part 9/18. Plan/Edition catalog management -- was previously read-only (Package::active() for a dropdown); this is the actual CRUD surface. */
    public function plans(): Response
    {
        return Inertia::render('Platform/Plans', [
            'plans' => Package::orderBy('sort_order')->get(),
        ]);
    }

    public function storePlan(Request $request): RedirectResponse
    {
        $validated = $this->validatePlan($request);
        Package::create($validated);

        ActivityLog::record('created', "Plan \"{$validated['name']}\" was created.");

        return back()->with('success', 'Plan created.');
    }

    public function updatePlan(Request $request, Package $plan): RedirectResponse
    {
        $validated = $this->validatePlan($request, $plan);
        $plan->update($validated);

        ActivityLog::record('updated', "Plan \"{$plan->name}\" was updated.");

        return back()->with('success', 'Plan updated.');
    }

    /**
     * v2.14.0 (SaaS Productization / Pricing Foundation, Part 3/7) added
     * `currency`/`trial_days`/`is_public`/`is_custom` -- see that
     * migration's own doc comment for why exactly these four and nothing
     * more. `price_monthly`/`price_yearly` stay `nullable` (unchanged) --
     * a `is_custom=true` plan legitimately has no fixed price at all, and
     * `PricingService` already knows to show "Hubungi Kami" instead of a
     * number for it rather than treating a null as zero.
     */
    private function validatePlan(Request $request, ?Package $plan = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', Rule::unique('packages', 'slug')->ignore($plan?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_monthly' => ['nullable', 'numeric', 'min:0'],
            'price_yearly' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'max_companies' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['boolean'],
            'is_public' => ['boolean'],
            'is_custom' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
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
