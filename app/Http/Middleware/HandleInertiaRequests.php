<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Services\WorkCenterService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function __construct(private readonly WorkCenterService $workCenter) {}

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Data shared with every Inertia page/component. This is how the React
     * frontend knows the logged-in user's role and capability flags, to
     * conditionally render Edit/Delete/Input KPI/Settings/Project UI
     * without a separate API call per page.
     *
     * NOTE: `company` (singular) below is the app-level branding config
     * (app name shown in the sidebar, logo) -- unrelated to the new
     * `companies` (plural) business entities (GAJ, Maintenance) used for
     * multi-company scoping, which are exposed separately as `companies`.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'role_label' => $user->roleLabel(),
                    'is_admin' => $user->isAdmin(), // back-compat: super_admin OR hse
                    'is_super_admin' => $user->isSuperAdmin(),
                    'is_hse' => $user->isHse(),
                    'is_hrd' => $user->isHrd(),
                    'is_manager' => $user->isManager(),
                    'is_warehouse' => $user->isWarehouse(),
                    'can_manage_operational_settings' => $user->canManageOperationalSettings(),
                    'can_manage_system_settings' => $user->canManageSystemSettings(),
                    'can_manage_projects' => $user->canManageProjects(),
                    'can_manage_leave_requests' => $user->canManageLeaveRequests(),
                    'can_manage_incidents' => $user->canManageIncidents(),
                    'can_manage_milestones' => $user->canManageMilestones(),
                    'can_manage_goods_receipts' => $user->canManageGoodsReceipts(),
                    // Department User mechanism (v1.10.2) -- see
                    // User::isDepartmentUser()'s own doc comment.
                    'department_key' => $user->department_key,
                    'is_department_user' => $user->isDepartmentUser(),
                    'avatar_url' => $user->avatarUrl(),
                ] : null,
            ],
            'company' => [
                'name' => CompanySetting::get('company_name', 'Integrated Operations Management System'),
                'subtitle' => CompanySetting::get('company_subtitle', 'Industrial Operations Platform'),
                'short_name' => CompanySetting::get('company_short_name'),
                'footer_copyright' => CompanySetting::get('footer_copyright'),
                'logo_path' => CompanySetting::get('company_logo_path'),
                'favicon_url' => CompanySetting::get('company_favicon_path')
                    ? asset('storage/'.CompanySetting::get('company_favicon_path'))
                    : null,
                // Computed once, here, so every page (sidebar, Login, About
                // dialog, Settings preview) uses the exact same URL instead
                // of each reconstructing "storage/" + path by hand -- the
                // v1.5.2 fix for the branding logo never actually appearing
                // anywhere after upload.
                'logo_url' => CompanySetting::get('company_logo_path')
                    ? asset('storage/'.CompanySetting::get('company_logo_path'))
                    : null,
            ],
            // Centralized branding (v1.5.3): the Wordmark + Brand Icon system
            // that replaces ad-hoc per-page images. Every value here is
            // resolved ONCE, in one place -- an admin-uploaded override
            // (company_setting, currently always null since no upload UI
            // exists yet -- see ROADMAP.md) takes priority, falling back to
            // the shipped default asset otherwise. No page/component should
            // ever reference an image path directly; they all read this.
            'branding' => [
                'wordmark_url' => CompanySetting::get('brand_wordmark_path')
                    ? asset('storage/'.CompanySetting::get('brand_wordmark_path'))
                    : asset(config('branding.default_wordmark_path')),
                'icon_url' => CompanySetting::get('brand_icon_path')
                    ? asset('storage/'.CompanySetting::get('brand_icon_path'))
                    : asset(config('branding.default_icon_path')),
                'watermark_enabled' => (bool) CompanySetting::get('watermark_enabled', config('branding.watermark_enabled')),
                'dashboard_watermark_enabled' => (bool) CompanySetting::get('dashboard_watermark_enabled', config('branding.dashboard_watermark_enabled')),
                'login_watermark_enabled' => (bool) CompanySetting::get('login_watermark_enabled', config('branding.login_watermark_enabled')),
                'home_watermark_enabled' => (bool) CompanySetting::get('home_watermark_enabled', config('branding.home_watermark_enabled')),
                'about_watermark_enabled' => (bool) CompanySetting::get('about_watermark_enabled', config('branding.about_watermark_enabled')),
                'watermark_opacity' => (float) CompanySetting::get('watermark_opacity', config('branding.watermark_opacity')),
            ],
            'modules' => [
                'available' => config('modules.available'),
                'enabled' => (function () {
                    $allKeys = array_keys(config('modules.available'));

                    // v1.6.8 (second pass): reads the database directly,
                    // deliberately bypassing CompanySetting::get()'s cache
                    // for this one setting. Two real bugs have now come
                    // from caching this specific value across two
                    // sessions -- first a stale-default bug (the cache
                    // was written once, before material_requests existed
                    // in config, and rememberForever never re-evaluated
                    // it), then a cache-key/forget-key mismatch bug found
                    // while fixing that (get()'s key gained a hash suffix
                    // that set()'s forget() call was never updated to
                    // match, so saving Settings > Modules stopped
                    // actually invalidating anything). This is a tiny,
                    // rarely-changed, indexed single-row lookup -- it was
                    // never a hot enough path to need forever-caching in
                    // the first place, and removing that dependency here
                    // removes this entire class of "my toggle change
                    // isn't taking effect" bug permanently, rather than
                    // trying to get the caching correct a third time.
                    $stored = json_decode(
                        CompanySetting::where('key', 'enabled_modules')->value('value') ?? json_encode($allKeys),
                        true
                    ) ?? $allKeys;

                    // A module newly added to config can be absent from
                    // an ALREADY-SAVED enabled_modules row (one an admin
                    // explicitly saved via Settings > Modules before the
                    // new module existed at all) -- that's a stale
                    // stored value, unrelated to caching. Only the
                    // modules explicitly listed here as "introduced this
                    // version" get unioned in as enabled-by-default for
                    // any pre-existing stored list -- deliberately NOT
                    // every current config key, since that would
                    // silently re-enable anything a company had
                    // genuinely chosen to turn off.
                    $newlyAddedModules = ['material_requests'];

                    return array_values(array_unique([
                        ...$stored,
                        ...array_intersect($newlyAddedModules, $allKeys),
                    ]));
                })(),
            ],
            'companies' => fn () => Company::active()->orderBy('name')->get(['id', 'name', 'code']),
            // Real data (PPE expiring within 30 days or already expired),
            // not a placeholder -- kept for Dashboard/Index.jsx's existing
            // ppeAlertCount prop. Wrapped in a closure so it's only
            // queried on requests where Inertia actually needs it
            // (partial reloads skip unused props).
            'notifications' => fn () => [
                'ppe_alert_count' => $user ? $this->workCenter->ppeAlertCount() : 0,
            ],
            // Work Center (v1.8.0) topbar badge counts -- same
            // WorkCenterService queries the full Work Center page uses,
            // just counted rather than shaped, so the badge can never
            // drift out of sync with what the page actually shows.
            'work_center' => fn () => [
                'approvals_count' => $this->workCenter->pendingApprovalsFor($user)->count(),
                'tasks_count' => $this->workCenter->myTasksFor($user)->count(),
                'alerts_count' => $user ? $this->workCenter->ppeAlertCount() : 0,
            ],
            'version' => [
                'number' => config('ioms.version'),
                'stage' => config('ioms.stage'),
                'edition' => config('ioms.edition'),
                'build' => config('ioms.build'),
                'release_date' => config('ioms.release_date'),
                'developer' => config('ioms.developer'),
                'company' => config('ioms.company'),
                'copyright_year' => config('ioms.copyright_year'),
                'license' => config('ioms.license'),
                'website' => config('ioms.website'),
                'support_email' => config('ioms.support_email'),
                'documentation_url' => config('ioms.documentation_url'),
                'whats_new' => config('ioms.whats_new'),
                'history' => config('ioms.version_history'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
