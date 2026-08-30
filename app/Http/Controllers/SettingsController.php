<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreKpiCategoryRequest;
use App\Http\Requests\UpdateAuthenticationRequest;
use App\Http\Requests\UpdateKpiCategoryRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Department;
use App\Models\KpiCategory;
use App\Models\Module;
use App\Models\NumberingFormat;
use App\Models\Position;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Settings module. Two permission tiers, enforced both by route middleware
 * (see routes/web.php: role:super_admin,hse for operational settings vs
 * role:super_admin for Company/User management) and by policy/manual
 * checks below as defense-in-depth:
 *   - Departments & Positions: Super Admin + HSE (canManageOperationalSettings)
 *   - Companies, Users, Backup/Restore, app branding: Super Admin only (canManageSystemSettings)
 */
class SettingsController extends Controller
{
    /**
     * Company-Scoped Master Data (v1.6.10). Departments and Positions
     * are now filtered by an optional `company_id` query param, matching
     * the exact same per-request filter pattern already used everywhere
     * else in this app (Dashboard, PPE, Reports, Employees) -- no
     * persistent "Active Company" session concept exists anywhere in
     * this codebase to reuse instead, so this follows the established
     * convention rather than introducing a new one.
     */
    public function index(Request $request): Response
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        return Inertia::render('Settings/Index', [
            // Milestone 3 (Company Settings completion, Task #62): was
            // missing short_name/footer_copyright/logo_url/favicon_url
            // entirely -- BrandingTab's edit form reads all four, so
            // they always rendered blank on load even after being saved.
            // Also adds address/phone/email/website/brand_color, the
            // fields UAT asked for that had no backing setting at all
            // yet -- for the future Document Engine (Task #66) to use
            // automatically, not consumed anywhere yet.
            'company' => [
                'name' => CompanySetting::get('company_name'),
                'subtitle' => CompanySetting::get('company_subtitle'),
                'short_name' => CompanySetting::get('company_short_name'),
                'footer_copyright' => CompanySetting::get('footer_copyright'),
                'logo_path' => CompanySetting::get('company_logo_path'),
                'logo_url' => CompanySetting::get('company_logo_path') ? asset('storage/'.CompanySetting::get('company_logo_path')) : null,
                'favicon_url' => CompanySetting::get('company_favicon_path') ? asset('storage/'.CompanySetting::get('company_favicon_path')) : null,
                'address' => CompanySetting::get('company_address'),
                'phone' => CompanySetting::get('company_phone'),
                'email' => CompanySetting::get('company_email'),
                'website' => CompanySetting::get('company_website'),
                'brand_color' => CompanySetting::get('brand_color', '#2563eb'),
            ],
            'companies' => Company::withCount(['employees', 'departments'])->orderBy('name')->get(),
            'departments' => Department::with('company:id,name')->withCount('employees')->inCompany($companyId)->ordered()->get(),
            'positions' => Position::with('company:id,name', 'department:id,name')->inCompany($companyId)->ordered()->get(),
            'kpiCategories' => KpiCategory::with('company:id,name')->orderBy('sort_order')->orderBy('name')->get(),
            // Milestone 3 (UAT #1/#7 -- identity clarity, found while
            // verifying Task #61): was missing a tenant_id filter
            // entirely -- a Company Admin's own User Management page was
            // showing EVERY user across EVERY tenant, including the
            // Platform Master account (tenant_id null). A real
            // cross-tenant data leak once a second tenant exists, and the
            // exact identity confusion UAT flagged, visible directly in
            // this list. `whereNull` deliberately excluded too -- Master
            // has no business appearing in ANY tenant's user list.
            // v1.10.7: `department_key` now selected too, so the Users tab
            // can actually display/edit it -- see storeUser()'s own doc
            // comment for why this was missing.
            'users' => User::with('roles:id,name')->where('tenant_id', $request->user()->tenant_id)->orderBy('name')->get(['id', 'name', 'email', 'role', 'department_key', 'is_active', 'last_login_at'])
                ->map(fn (User $u) => [
                    ...$u->only(['id', 'name', 'email', 'role', 'department_key', 'is_active', 'last_login_at']),
                    'role_ids' => $u->roles->pluck('id'),
                ]),
            'filters' => ['company_id' => $companyId],
            'can' => [
                'manage_operational' => request()->user()->canManageOperationalSettings(),
                'manage_system' => request()->user()->canManageSystemSettings(),
            ],
            // Milestone 2 (RBAC UI, Task #45). Tenant-side roles only --
            // Role::where('tenant_id', ...) already excludes the
            // Platform Super Admin role (seeded under a `0` sentinel, see
            // docs/ADR/008-tenancy-foundation.md), so a Company Admin can
            // never see or edit that one from here.
            'roles' => Role::where('tenant_id', $request->user()->tenant_id)
                ->with('permissions')
                ->orderBy('name')
                ->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'permissions' => $role->permissions->pluck('name'),
                ]),
            'permissionCatalog' => array_values(array_filter(
                config('permission_catalog.permissions', []),
                fn ($permission) => ! str_starts_with($permission, 'platform.')
            )),
            // Milestone 3 (Company Settings completion, Task #62). One
            // row per module -- the tenant's own customization if they've
            // saved one (`tenant_id` set, `company_id` null), else
            // NumberGeneratorService's own hardcoded default (not
            // persisted just from viewing this page -- only saving
            // actually creates a tenant-scoped row).
            'numberingFormats' => collect(\App\Services\NumberGeneratorService::DEFAULTS)->map(function ($default, $moduleKey) use ($request) {
                $existing = NumberingFormat::whereNull('company_id')
                    ->where('tenant_id', $request->user()->tenant_id)
                    ->where('module_key', $moduleKey)
                    ->first();

                return [
                    'module_key' => $moduleKey,
                    'prefix' => $existing->prefix ?? $default['prefix'],
                    'pattern' => $existing->pattern ?? $default['pattern'],
                    'seq_padding' => $existing->seq_padding ?? $default['seq_padding'],
                    'reset_period' => $existing->reset_period ?? $default['reset_period'],
                ];
            })->values(),
            // Milestone 3 (Company Settings completion, Task #62).
            // Tenant-scoped ApprovalFlow rows only -- see
            // docs/ADR/018-approval-flow-numbering-tenant-scoping.md for
            // why `tenant_id` had to be added to this table in this same
            // pass.
            'approvalFlows' => \App\Models\ApprovalFlow::where('tenant_id', $request->user()->tenant_id)
                ->with('steps')
                ->orderBy('module_key')
                ->get(),
            'numberingModuleKeys' => array_keys(\App\Services\NumberGeneratorService::DEFAULTS),
            'notificationPreferences' => \App\Services\NotificationService::preferences(),
            // Milestone 3 (Dynamic Document Engine, Task #66). Tenant-wide
            // templates only (company_id null) -- same "no per-company
            // override UI yet" scope decision Approval Flow made in
            // ADR-018; the schema/engine already support company_id, a
            // Company Admin needing one reaches for tinker today.
            'documentTemplates' => \App\Models\DocumentTemplate::where('tenant_id', $request->user()->tenant_id)
                ->whereNull('company_id')
                ->orderBy('module_key')
                ->get(),
            'documentModuleKeys' => array_keys(\App\Services\NumberGeneratorService::DEFAULTS),
            // Milestone 3 (Import/Export Mapping, Task #67). Employees is
            // the only real importer/exporter today (EmployeesImport/
            // EmployeeExport) -- config/mapping_fields.php's catalog IS
            // the module list this tab can offer; a future importer adds
            // itself there, not here.
            'fieldMappings' => collect(array_keys(config('mapping_fields', [])))->mapWithKeys(fn ($moduleKey) => [
                $moduleKey => [
                    'import' => app(\App\Services\FieldMappingService::class)->resolve($moduleKey, 'import'),
                    'export' => app(\App\Services\FieldMappingService::class)->resolve($moduleKey, 'export'),
                ],
            ]),
            // v1.11.0 (SaaS Finalization Pass, Part 19). Tenant Admin's
            // own read-only view of their commercial record -- Platform
            // Admin (PlatformController) is the only place that can
            // CHANGE it, matching "Do NOT give Tenant Admin platform-
            // level access." Only this tenant's own tenant_id is ever
            // queried, never another tenant's.
            'subscription' => (function () use ($request) {
                $tenant = $request->user()->tenant;
                $subscription = $tenant?->subscription;

                if (! $subscription) {
                    return null;
                }

                return [
                    'package_name' => $subscription->package?->name,
                    'type' => $subscription->type,
                    'status' => $subscription->status,
                    'billing_cycle' => $subscription->billing_cycle,
                    'seat_limit' => $subscription->seatLimit(),
                    'starts_at' => $subscription->starts_at,
                    'ends_at' => $subscription->ends_at,
                    'trial_ends_at' => $subscription->trial_ends_at,
                    'is_usable' => $subscription->isUsable(),
                    'is_degraded' => $subscription->isDegraded(),
                ];
            })(),
            'invoices' => \App\Models\Invoice::where('tenant_id', $request->user()->tenant_id)
                ->latest()
                ->get(['id', 'invoice_number', 'amount', 'currency', 'status', 'due_date', 'payment_date']),
        ]);
    }

    /**
     * v2.14.0 (SaaS Productization / Pricing Foundation, Part 8/9). The
     * tenant-facing Plans/pricing comparison page -- data-driven entirely
     * from `PricingService`/`Package`, never a hand-maintained UI table,
     * so it can never drift from what a Platform Admin actually
     * configured in Platform > Plans. Deliberately open to every
     * authenticated tenant user (not gated to Super Admin the way
     * updateCompany()/Company Settings are) -- knowing what plans exist
     * and what the tenant's own plan includes is not privileged
     * information, matching Part 8's "tenant-facing subscription
     * overview" requirement. A Platform Admin has no tenant (see
     * User::isPlatformAdmin()) and reaches this same information via
     * Platform > Plans instead -- RestrictPlatformAdminFromTenantRoutes
     * already keeps them off tenant routes generally.
     *
     * No checkout, no payment action here -- see this page's own "Contact
     * administrator to upgrade" CTA. Upgrading a plan remains a Platform
     * Admin action via PlatformController::updateSubscription(), exactly
     * as it already was before this page existed.
     */
    public function plans(Request $request): Response
    {
        $pricing = app(PricingService::class);
        $tenant = $request->user()->tenant;
        $currentPackage = $tenant?->subscription?->package;

        return Inertia::render('Subscription/Plans', [
            'plans' => $pricing->publicPlans(),
            'currentPlan' => $currentPackage ? $pricing->summarize($currentPackage) : null,
            'currentPlanId' => $currentPackage?->id,
        ]);
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_subtitle' => ['nullable', 'string', 'max:255'],
            'company_short_name' => ['nullable', 'string', 'max:50'],
            'footer_copyright' => ['nullable', 'string', 'max:255'],
            // Milestone 3 (Company Settings completion, Task #62) --
            // feeds the future Document Engine's letterhead (Task #66),
            // not consumed anywhere yet.
            'company_address' => ['nullable', 'string', 'max:500'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_website' => ['nullable', 'string', 'max:255'],
            'brand_color' => ['nullable', 'string', 'max:7'],
            // Laravel's `image` rule does NOT include SVG by default --
            // explicit mimes list needed to actually support the SVG
            // upload requested (v1.6.3).
            'logo' => ['nullable', 'mimes:jpg,jpeg,png,svg,webp', 'max:2048'],
            'favicon' => ['nullable', 'mimes:jpg,jpeg,png,svg,webp,ico', 'max:512'],
        ]);

        CompanySetting::set('company_name', $validated['company_name']);
        CompanySetting::set('company_subtitle', $validated['company_subtitle'] ?? 'Industrial Operations Platform');
        CompanySetting::set('company_short_name', $validated['company_short_name'] ?? '');
        CompanySetting::set('footer_copyright', $validated['footer_copyright'] ?? '');
        CompanySetting::set('company_address', $validated['company_address'] ?? '');
        CompanySetting::set('company_phone', $validated['company_phone'] ?? '');
        CompanySetting::set('company_email', $validated['company_email'] ?? '');
        CompanySetting::set('company_website', $validated['company_website'] ?? '');
        CompanySetting::set('brand_color', $validated['brand_color'] ?? '#2563eb');

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('uploads/company', 'public');
            CompanySetting::set('company_logo_path', $path);
        }

        if ($request->hasFile('favicon')) {
            $path = $request->file('favicon')->store('uploads/company', 'public');
            CompanySetting::set('company_favicon_path', $path);
        }

        ActivityLog::record('updated', 'Application branding settings updated.');

        return back()->with('success', 'Settings updated.');
    }

    // --- Modules (Super Admin only: enable/disable sidebar modules) ---

    /**
     * Toggles which sidebar modules are enabled app-wide. Core modules
     * (Home, Dashboard, Settings) are never in this list -- they're
     * always on. Milestone 3 (UAT #4): the whitelist is no longer every
     * module that exists globally -- it's only the subset the Platform
     * has GRANTED to this tenant (`tenant_modules`, see the granting
     * migration's doc comment). A Company Admin can turn a granted
     * module's sidebar visibility on/off; they cannot enable a module
     * their tenant was never granted, no matter what key is submitted.
     */
    public function updateModules(Request $request): RedirectResponse
    {
        $grantedKeys = $request->user()->tenant?->modules()->pluck('key')->all() ?? [];

        $validated = $request->validate([
            'enabled_modules' => ['array'],
            'enabled_modules.*' => ['string', Rule::in($grantedKeys)],
        ]);

        CompanySetting::set('enabled_modules', json_encode($validated['enabled_modules'] ?? []));

        ActivityLog::record('updated', 'Enabled modules were updated.');

        return back()->with('success', 'Modules updated.');
    }

    /**
     * Milestone 2 (Dynamic Workspace system, Task #43). Bulk-updates the
     * label/order/active-state of existing `workspaces` catalog rows --
     * same "visibility/labeling only" boundary as updateModules() above,
     * not a way to create a workspace with real functionality (see the
     * workspaces migration's own doc comment). Every `key` submitted must
     * already exist in the DB (seeded by WorkspaceSeeder from
     * resources/js/lib/workspaces.js's own WORKSPACES array), so this can
     * never invent a workspace key the frontend doesn't already know how
     * to render.
     */
    public function updateWorkspaces(Request $request): RedirectResponse
    {
        // Milestone 3 (UAT #5): same grant boundary as updateModules() --
        // a Company Admin can only rename/reorder/toggle a workspace
        // their tenant was actually granted by Platform.
        $grantedKeys = $request->user()->tenant?->workspaces()->pluck('key')->all() ?? [];

        $validated = $request->validate([
            'workspaces' => ['array'],
            'workspaces.*.key' => ['required', 'string', Rule::in($grantedKeys)],
            'workspaces.*.label' => ['required', 'string', 'max:255'],
            'workspaces.*.sort_order' => ['required', 'integer', 'min:0'],
            'workspaces.*.is_active' => ['boolean'],
        ]);

        foreach ($validated['workspaces'] ?? [] as $row) {
            Workspace::where('key', $row['key'])->update([
                'label' => $row['label'],
                'sort_order' => $row['sort_order'],
                'is_active' => $row['is_active'] ?? true,
            ]);
        }

        ActivityLog::record('updated', 'Department navigation labels/order were updated.');

        return back()->with('success', 'Departments updated.');
    }

    /**
     * Milestone 3 (Company Settings completion, Task #62). Saves this
     * tenant's own numbering format customization -- always writes a
     * tenant-scoped row (`tenant_id` set, `company_id` null), never the
     * platform-wide fallback row, so this can never affect another
     * tenant. See `NumberGeneratorService::resolveFormat()`'s own doc
     * comment for the full resolution order.
     */
    public function updateNumberingFormats(Request $request): RedirectResponse
    {
        $validKeys = array_keys(\App\Services\NumberGeneratorService::DEFAULTS);

        $validated = $request->validate([
            'formats' => ['array'],
            'formats.*.module_key' => ['required', 'string', Rule::in($validKeys)],
            'formats.*.prefix' => ['required', 'string', 'max:10'],
            'formats.*.pattern' => ['required', 'string', 'max:100'],
            'formats.*.seq_padding' => ['required', 'integer', 'min:1', 'max:10'],
            'formats.*.reset_period' => ['required', Rule::in(['yearly', 'monthly', 'never'])],
        ]);

        $tenantId = $request->user()->tenant_id;

        foreach ($validated['formats'] ?? [] as $row) {
            NumberingFormat::updateOrCreate(
                ['tenant_id' => $tenantId, 'company_id' => null, 'module_key' => $row['module_key']],
                ['prefix' => $row['prefix'], 'pattern' => $row['pattern'], 'seq_padding' => $row['seq_padding'], 'reset_period' => $row['reset_period']]
            );
        }

        ActivityLog::record('updated', 'Document numbering formats were updated.');

        return back()->with('success', 'Numbering formats updated.');
    }

    /**
     * Milestone 3 (Company Settings completion, Task #62). See
     * NotificationService's own doc comment for the storage/scope caveat.
     */
    public function updateNotificationPreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.approval' => ['boolean'],
            'preferences.reminder' => ['boolean'],
            'preferences.warning' => ['boolean'],
            'preferences.success' => ['boolean'],
            'preferences.information' => ['boolean'],
        ]);

        CompanySetting::set('notification_preferences', json_encode($validated['preferences']));

        ActivityLog::record('updated', 'Notification preferences were updated.');

        return back()->with('success', 'Notification preferences updated.');
    }

    /**
     * Milestone 3 (Company Settings completion, Task #62). Creates a
     * tenant-wide flow for one module (company_id null -- applies to
     * every company under this tenant; per-company overrides aren't
     * exposed from this UI yet, only via direct DB/tinker). Always
     * writes `tenant_id` explicitly -- see
     * docs/ADR/018-approval-flow-numbering-tenant-scoping.md for why
     * that column exists at all.
     */
    public function storeApprovalFlow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'module_key' => ['required', 'string', Rule::in(array_keys(\App\Services\NumberGeneratorService::DEFAULTS))],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $flow = \App\Models\ApprovalFlow::create([
            'tenant_id' => $request->user()->tenant_id,
            'company_id' => null,
            'module_key' => $validated['module_key'],
            'name' => $validated['name'],
            'is_active' => true,
            'priority' => 0,
        ]);

        ActivityLog::record('created', "Approval flow \"{$flow->name}\" was created for {$flow->module_key}.", $flow);

        return back()->with('success', 'Approval flow created.');
    }

    public function destroyApprovalFlow(Request $request, \App\Models\ApprovalFlow $approvalFlow): RedirectResponse
    {
        abort_unless($approvalFlow->tenant_id === $request->user()->tenant_id, 404);

        $name = $approvalFlow->name;
        $approvalFlow->delete();

        ActivityLog::record('deleted', "Approval flow \"{$name}\" was removed.");

        return back()->with('success', 'Approval flow removed. That module now uses the standard single-step approval.');
    }

    /**
     * Replaces a flow's entire step list -- simpler and safer than
     * fine-grained add/edit/remove endpoints for what is, from the UI's
     * perspective, one form submit per flow.
     */
    public function updateApprovalFlowSteps(Request $request, \App\Models\ApprovalFlow $approvalFlow): RedirectResponse
    {
        abort_unless($approvalFlow->tenant_id === $request->user()->tenant_id, 404);

        $validated = $request->validate([
            'steps' => ['array'],
            'steps.*.step_number' => ['required', 'integer', 'min:1'],
            'steps.*.mode' => ['required', Rule::in(['single', 'parallel_any', 'parallel_all'])],
            'steps.*.approver_role' => ['required', 'string', Rule::in(['super_admin', 'hse', 'hrd', 'manager', 'warehouse'])],
            'steps.*.escalate_after_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'steps.*.escalate_to_role' => ['nullable', 'string', Rule::in(['super_admin', 'hse', 'hrd', 'manager', 'warehouse'])],
        ]);

        $approvalFlow->steps()->delete();

        foreach ($validated['steps'] ?? [] as $step) {
            $approvalFlow->steps()->create($step);
        }

        ActivityLog::record('updated', "Approval flow \"{$approvalFlow->name}\" steps were updated.", $approvalFlow);

        return back()->with('success', 'Approval steps updated.');
    }

    /**
     * Milestone 2 (RBAC UI, Task #45). Syncs a tenant-side Role's
     * permission set. `RolePermissionSeeder` still defines each role's
     * DEFAULT set on first seed; this is where a Company Admin can
     * actually change it afterward without a developer touching seed
     * code. Does not (yet) migrate any controller's own authorization
     * check from `role`/`isX()` to `->can()` -- see
     * docs/ADR/008-tenancy-foundation.md's RBAC decision -- so editing a
     * role's permissions here has no effect on the app's actual behavior
     * until that separate migration happens. Documented plainly in the
     * frontend UI itself so this isn't a surprise.
     */
    public function updateRolePermissions(Request $request, Role $role): RedirectResponse
    {
        // A Role belonging to another tenant (or the platform_admin
        // role, tenant_id 0) must never be editable from here, no matter
        // what id is guessed in the URL.
        abort_unless($role->tenant_id === $request->user()->tenant_id, 404);

        $validCatalog = array_filter(config('permission_catalog.permissions', []), fn ($p) => ! str_starts_with($p, 'platform.'));

        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in($validCatalog)],
        ]);

        $role->syncPermissions($validated['permissions'] ?? []);

        ActivityLog::record('updated', "Role \"{$role->name}\" permissions were updated.");

        return back()->with('success', 'Role permissions updated.');
    }

    /**
     * Milestone 3 (UAT #6 -- dynamic Role/Permission). A Company Admin
     * can now create a genuinely new tenant-scoped role, not just edit
     * permissions on the 5 built-in ones RolePermissionSeeder creates.
     * This IS a real, functional Spatie role -- `$user->hasRole()`/
     * `->can()` reflect it immediately for anything that checks
     * permissions. What it is NOT (yet): the CONTROLLING authorization
     * path for existing features, which all still run on the `role`
     * column + isX()/canX() methods (see
     * docs/ADR/008-tenancy-foundation.md's RBAC decision). A user
     * assigned only a custom role, with no `role` column value change,
     * keeps whatever their `role` column already grants -- creating a
     * custom role does not take anything away.
     */
    public function storeRole(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $tenantId = $request->user()->tenant_id;
        $key = \Illuminate\Support\Str::slug($validated['name'], '_');

        abort_if(in_array($key, [
            User::ROLE_SUPER_ADMIN, User::ROLE_HSE, User::ROLE_HRD, User::ROLE_MANAGER, User::ROLE_WAREHOUSE,
        ], true), 422, 'That name collides with a built-in role.');

        $role = Role::create(['name' => $key, 'guard_name' => 'web', 'tenant_id' => $tenantId]);

        ActivityLog::record('created', "Custom role \"{$validated['name']}\" was created.");

        return back()->with('success', "Role \"{$validated['name']}\" created.");
    }

    public function destroyRole(Request $request, Role $role): RedirectResponse
    {
        abort_unless($role->tenant_id === $request->user()->tenant_id, 404);

        abort_if(in_array($role->name, [
            User::ROLE_SUPER_ADMIN, User::ROLE_HSE, User::ROLE_HRD, User::ROLE_MANAGER, User::ROLE_WAREHOUSE,
        ], true), 422, 'Built-in roles cannot be deleted.');

        $name = $role->name;
        $role->delete();

        ActivityLog::record('deleted', "Custom role \"{$name}\" was removed.");

        return back()->with('success', 'Role removed.');
    }

    /**
     * Assigns/unassigns a user to a custom role -- additive, does not
     * touch that user's own `role` column (their existing capability is
     * unaffected either way, see storeRole()'s own doc comment).
     */
    public function updateUserRoles(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->tenant_id === $request->user()->tenant_id, 404);

        $validated = $request->validate([
            'role_ids' => ['array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        // Always keeps the user's base role (matching their `role` column)
        // in the Spatie assignment too -- syncRoles() replaces the full
        // set, and dropping the base role here would desync
        // ->hasRole($user->role) from what the column actually says, even
        // though nothing reads that today. Custom role ids are additive
        // on top of it.
        $customRoleNames = Role::whereIn('id', $validated['role_ids'] ?? [])->pluck('name')->all();
        $user->syncRoles([$user->role, ...$customRoleNames]);

        ActivityLog::record('updated', "Roles for user \"{$user->name}\" were updated.");

        return back()->with('success', 'User roles updated.');
    }

    // --- Companies (business entities: GAJ, Maintenance) ---

    public function storeCompanyEntity(Request $request): RedirectResponse
    {
        // Milestone 3 (UAT #1/#7 -- same audit as Task #61's other
        // findings): this never set tenant_id at all -- `companies.tenant_id`
        // is NOT NULL (Milestone 2), so "Add Company" from Settings was
        // actually crashing with a DB error for every Company Admin,
        // not silently leaking. The `unique:companies,*` rules also
        // validated against the raw table, bypassing TenantScope --
        // scoped to the current tenant here instead, so two different
        // tenants CAN both have a company named "GAJ".
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('companies', 'name')->where('tenant_id', $tenantId)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('companies', 'code')->where('tenant_id', $tenantId)],
        ]);

        Company::create([...$data, 'tenant_id' => $tenantId]);

        return back()->with('success', 'Company added.');
    }

    public function updateCompanyEntity(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('companies', 'name')->where('tenant_id', $company->tenant_id)->ignore($company->id)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('companies', 'code')->where('tenant_id', $company->tenant_id)->ignore($company->id)],
            'is_active' => ['boolean'],
        ]);

        $company->update($data);

        return back()->with('success', 'Company updated.');
    }

    public function destroyCompanyEntity(Company $company): RedirectResponse
    {
        if ($company->employees()->exists() || $company->departments()->exists()) {
            return back()->with('error', 'Cannot delete a company that still has departments or employees assigned.');
        }

        $company->delete();

        return back()->with('success', 'Company removed.');
    }

    // --- Departments ---

    /**
     * Milestone 3 (UAT #1/#7 -- same class of bug as Task #61's
     * SettingsController::updateUser() finding, audited proactively here
     * afterward). `Department`/`Position`/`KpiCategory` carry no
     * TenantScope of their own (only `Company` does -- see ADR-008's
     * "anchor" design, which relies on `company_id` FK chains, not a
     * scope on every table). That means implicit route-model-binding
     * (`Department $department`) does NOT filter by tenant at all -- an
     * Administrator could otherwise edit/delete another tenant's
     * Department/Position/KpiCategory by guessing its id. Worse,
     * `Rule::exists('companies', 'id')` validates against the RAW table,
     * bypassing Eloquent (and therefore TenantScope) entirely, so a
     * `company_id` belonging to a different tenant would otherwise pass
     * validation too. This helper is the one place that actually closes
     * both gaps: `Company::find()` (Eloquent, TenantScope applied) 404s
     * for any company id outside the current tenant.
     */
    private function assertCompanyInTenant(?int $companyId): void
    {
        if ($companyId === null) {
            return; // null company_id = a genuinely global/unscoped record (e.g. a global KPI category)
        }

        abort_unless(Company::find($companyId) !== null, 404);
    }

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('company_id', $request->input('company_id'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'code' => ['nullable', 'string', 'max:20', 'unique:departments,code'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $this->assertCompanyInTenant($data['company_id']);

        Department::create($data);

        return back()->with('success', 'Department added.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $this->assertCompanyInTenant($department->company_id);

        $companyId = $request->input('company_id', $department->company_id);

        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('company_id', $companyId)->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($department->id)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);

        $this->assertCompanyInTenant($data['company_id']);

        $department->update($data);

        return back()->with('success', 'Department updated.');
    }

    public function destroyDepartment(Department $department): RedirectResponse
    {
        $this->assertCompanyInTenant($department->company_id);

        if ($department->employees()->exists()) {
            return back()->with('error', 'Cannot delete a department that still has employees assigned.');
        }

        $department->delete();

        return back()->with('success', 'Department removed.');
    }

    // --- Positions ---

    public function storePosition(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'company_id' => ['required', 'exists:companies,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $this->assertCompanyInTenant($data['company_id']);

        Position::create($data);

        return back()->with('success', 'Position added.');
    }

    public function updatePosition(Request $request, Position $position): RedirectResponse
    {
        $this->assertCompanyInTenant($position->company_id);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'company_id' => ['required', 'exists:companies,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);

        $this->assertCompanyInTenant($data['company_id']);

        $position->update($data);

        return back()->with('success', 'Position updated.');
    }

    public function destroyPosition(Position $position): RedirectResponse
    {
        $this->assertCompanyInTenant($position->company_id);

        $position->delete();

        return back()->with('success', 'Position removed.');
    }

    // --- KPI Categories (Super Admin + HSE) ---
    //
    // KPI categories are fully data-driven -- never hardcoded. A category
    // with company_id = null applies to every company; one with company_id
    // set only applies to that company, so different companies can run
    // entirely different KPI sets (e.g. TRIR/LTIFR/Near Miss vs. Safety
    // Patrol/Training/Toolbox Meeting) with zero code changes. Dashboard,
    // Reports, and Input KPI all read from this table live.

    public function storeKpiCategory(StoreKpiCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $this->assertCompanyInTenant($validated['company_id'] ?? null);

        $category = KpiCategory::create($validated + ['is_active' => true]);

        ActivityLog::record('created', "KPI category \"{$category->name}\" was created.", $category);

        return back()->with('success', 'KPI category added.');
    }

    public function updateKpiCategory(UpdateKpiCategoryRequest $request, KpiCategory $kpiCategory): RedirectResponse
    {
        $this->assertCompanyInTenant($kpiCategory->company_id);

        $validated = $request->validated();
        $this->assertCompanyInTenant($validated['company_id'] ?? null);

        $kpiCategory->update($validated);

        ActivityLog::record('updated', "KPI category \"{$kpiCategory->name}\" was updated.", $kpiCategory);

        return back()->with('success', 'KPI category updated.');
    }

    public function destroyKpiCategory(KpiCategory $kpiCategory): RedirectResponse
    {
        $this->assertCompanyInTenant($kpiCategory->company_id);

        if ($kpiCategory->kpiRecords()->exists()) {
            return back()->with('error', 'Cannot delete a KPI category that already has recorded data. Deactivate it instead.');
        }

        $name = $kpiCategory->name;
        $kpiCategory->delete();

        ActivityLog::record('deleted', "KPI category \"{$name}\" was removed.");

        return back()->with('success', 'KPI category removed.');
    }

    // --- User Management (Super Admin only) ---

    /**
     * v1.10.7. The real, assignable subset of `config('departments')`'s
     * keys -- 'reports' and 'administration' are listed there too, but
     * only so their own routes are correctly denied to a Department User;
     * they're explicitly documented as "not real departments a user can
     * be assigned to" and must never be offered here.
     */
    private function assignableDepartmentKeys(): array
    {
        return collect(config('departments', []))
            ->keys()
            ->reject(fn (string $key) => in_array($key, ['reports', 'administration'], true))
            ->values()
            ->all();
    }

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:super_admin,hse,hrd,manager,warehouse'],
            // v1.10.7 fix: this form previously had NO way to set
            // department_key at all -- `role` (what actions a user may
            // perform) and `department_key` (which department's
            // navigation/routes a user is even allowed to reach) are
            // separate mechanisms (see User::isDepartmentUser()'s own doc
            // comment), but with no field for the second one here, every
            // user ever created through this UI silently stayed
            // department_key=null -- i.e. a full Administrator for
          // navigation-restriction purposes, REGARDLESS of what role was
            // picked. A tenant admin choosing role "HSE" reasonably
            // expected that alone to restrict the account to HSE, and it
            // never did. null (the default, unselected) still means
            // "Administrator: full Department Selector" -- this is purely
            // additive, no existing account's behavior changes.
            'department_key' => ['nullable', 'string', Rule::in($this->assignableDepartmentKeys())],
        ]);

        $data['password'] = Hash::make($data['password']);
        // Milestone 2 (Tenancy Foundation): every user created through this
        // form belongs to the same tenant as the admin creating them --
        // never leave tenant_id unset here, or the new account would
        // silently become a Platform Super Admin (tenant_id null, see
        // User::isPlatformAdmin()) and fail every Company-scoped query
        // closed for itself (TenantScope).
        $data['tenant_id'] = $request->user()->tenant_id;
        $user = User::create($data);

        ActivityLog::record('created', "User {$user->name} ({$user->role}) was created.", $user);

        return back()->with('success', 'User created.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        // Milestone 3 (UAT #1/#7 -- found while verifying Task #61): this
        // method never checked ownership at all -- role:super_admin only
        // restricts by ROLE, not by TENANT, so any Administrator could
        // PUT /settings/users/{any id} and rename/reset the password/
        // change the role of a user belonging to a DIFFERENT tenant (or
        // attempt it against the Master account). A real cross-tenant
        // account-takeover bug, not just a display issue.
        abort_unless($user->tenant_id === $request->user()->tenant_id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'in:super_admin,hse,hrd,manager,warehouse'],
            'is_active' => ['boolean'],
            // v1.10.7 fix -- see storeUser()'s own doc comment for the gap this closes.
            'department_key' => ['nullable', 'string', Rule::in($this->assignableDepartmentKeys())],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        ActivityLog::record('updated', "User {$user->name} was updated.", $user);

        return back()->with('success', 'User updated.');
    }

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $name = $user->name;
        $user->delete();

        ActivityLog::record('deleted', "User {$name} was removed.", $user);

        return back()->with('success', 'User removed.');
    }

    // --- Backup / Restore (Super Admin only) ---

    /**
     * Creates a mysqldump-based backup file and streams it for download.
     * Requires the `mysqldump` binary to be available on the server PATH.
     */
    public function backupDatabase(): mixed
    {
        $filename = 'ioms-backup-'.now()->format('Y-m-d_His').'.sql';
        $path = storage_path('app/backups/'.$filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $db = config('database.connections.mysql');
        $command = sprintf(
            'mysqldump -h%s -P%s -u%s %s %s > %s 2>&1',
            escapeshellarg($db['host']),
            escapeshellarg($db['port']),
            escapeshellarg($db['username']),
            $db['password'] ? '-p'.escapeshellarg($db['password']) : '',
            escapeshellarg($db['database']),
            escapeshellarg($path)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ! file_exists($path)) {
            return back()->with('error', 'Backup failed. Ensure mysqldump is installed and accessible on the server.');
        }

        ActivityLog::record('exported', 'Database backup created and downloaded.');

        return response()->download($path)->deleteFileAfterSend(true);
    }

    /**
     * Restores the database from an uploaded .sql backup file.
     * DESTRUCTIVE: overwrites current data. Super-Admin-only, confirmed client-side.
     */
    public function restoreDatabase(Request $request): RedirectResponse
    {
        $request->validate([
            'backup_file' => ['required', 'file', 'mimes:sql', 'max:51200'],
        ]);

        $uploadPath = $request->file('backup_file')->store('restores', 'local');
        $fullPath = storage_path('app/'.$uploadPath);

        $db = config('database.connections.mysql');
        $command = sprintf(
            'mysql -h%s -P%s -u%s %s %s < %s 2>&1',
            escapeshellarg($db['host']),
            escapeshellarg($db['port']),
            escapeshellarg($db['username']),
            $db['password'] ? '-p'.escapeshellarg($db['password']) : '',
            escapeshellarg($db['database']),
            escapeshellarg($fullPath)
        );

        exec($command, $output, $exitCode);
        Storage::disk('local')->delete($uploadPath);

        if ($exitCode !== 0) {
            return back()->with('error', 'Restore failed. Please verify the backup file is valid.');
        }

        ActivityLog::record('restored', 'Database restored from uploaded backup file.');

        return back()->with('success', 'Database restored successfully.');
    }

    // --- Authentication (self-service: change your OWN email/password) ---

    /**
     * Lets the currently-authenticated Super Admin/HSE user change their
     * own login email ("Username") and/or password. Requires the current
     * password to confirm identity. Future-ready for Email verification
     * and 2FA fields, per the spec -- neither is implemented yet, this
     * only adds the credential-change flow itself.
     */
    public function updateAuthentication(UpdateAuthenticationRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->email = $data['email'];
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        ActivityLog::record('updated', 'Changed their own login credentials.', $user);

        return back()->with('success', 'Your credentials have been updated.');
    }

    // --- Import/Export Mapping (Milestone 3, Task #67) ---

    public function updateFieldMapping(Request $request, \App\Services\FieldMappingService $mapping): RedirectResponse
    {
        $validated = $request->validate([
            'module_key' => ['required', 'string', Rule::in(array_keys(config('mapping_fields', [])))],
            'direction' => ['required', Rule::in(['import', 'export'])],
            'rows' => ['required', 'array'],
            'rows.*.field_key' => ['required', 'string'],
            'rows.*.column_label' => ['required', 'string', 'max:255'],
            'rows.*.is_enabled' => ['boolean'],
        ]);

        $mapping->upsert($validated['module_key'], $validated['direction'], $validated['rows']);

        ActivityLog::record('updated', "{$validated['direction']} mapping for {$validated['module_key']} was updated.");

        return back()->with('success', 'Field mapping updated.');
    }

    // --- Document Templates (Milestone 3, Dynamic Document Engine, Task #66) ---

    /**
     * Tenant-wide only (company_id null), same scope decision as
     * storeApprovalFlow. Setting is_default clears any other default for
     * the same module_key, app-enforced (not a DB constraint) -- same
     * pattern used everywhere else "one default per key" matters in
     * this app (NumberingFormat, ApprovalFlow priority resolution).
     */
    public function storeDocumentTemplate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'module_key' => ['required', 'string', Rule::in(array_keys(\App\Services\NumberGeneratorService::DEFAULTS))],
            'name' => ['required', 'string', 'max:255'],
            'header_text' => ['nullable', 'string', 'max:2000'],
            'footer_text' => ['nullable', 'string', 'max:2000'],
            'show_logo' => ['boolean'],
            'show_qr' => ['boolean'],
            'show_signature' => ['boolean'],
            'show_watermark' => ['boolean'],
            'watermark_text' => ['nullable', 'string', 'max:100'],
        ]);

        $tenantId = $request->user()->tenant_id;

        $template = \App\Models\DocumentTemplate::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'company_id' => null,
            'is_default' => true,
        ]);

        \App\Models\DocumentTemplate::where('tenant_id', $tenantId)
            ->whereNull('company_id')
            ->where('module_key', $validated['module_key'])
            ->whereKeyNot($template->id)
            ->update(['is_default' => false]);

        ActivityLog::record('created', "Document template \"{$template->name}\" was created for {$template->module_key}.", $template);

        return back()->with('success', 'Document template created.');
    }

    public function updateDocumentTemplate(Request $request, \App\Models\DocumentTemplate $documentTemplate): RedirectResponse
    {
        abort_unless($documentTemplate->tenant_id === $request->user()->tenant_id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'header_text' => ['nullable', 'string', 'max:2000'],
            'footer_text' => ['nullable', 'string', 'max:2000'],
            'show_logo' => ['boolean'],
            'show_qr' => ['boolean'],
            'show_signature' => ['boolean'],
            'show_watermark' => ['boolean'],
            'watermark_text' => ['nullable', 'string', 'max:100'],
        ]);

        $documentTemplate->update($validated);

        ActivityLog::record('updated', "Document template \"{$documentTemplate->name}\" was updated.", $documentTemplate);

        return back()->with('success', 'Document template updated.');
    }

    public function destroyDocumentTemplate(Request $request, \App\Models\DocumentTemplate $documentTemplate): RedirectResponse
    {
        abort_unless($documentTemplate->tenant_id === $request->user()->tenant_id, 404);

        $name = $documentTemplate->name;
        $documentTemplate->delete();

        ActivityLog::record('deleted', "Document template \"{$name}\" was removed.");

        return back()->with('success', 'Document template removed. That module now uses its default appearance.');
    }
}
