<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Services\EntitlementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * v2.83.0 -- ADMIN SPACE, AND WHY IT IS NOT MASTER ADMIN.
 *
 * Two administrative surfaces exist and they belong to different
 * companies:
 *
 *   MASTER ADMIN (/platform)  the IOMS OPERATOR's console. Tenants,
 *                             packages, subscriptions, payments, support.
 *                             Gated on `role:platform_admin`, its users
 *                             hold `tenant_id IS NULL`, and it is written
 *                             in Bahasa Indonesia throughout (ADR 040).
 *
 *   ADMIN SPACE (/admin)      the CUSTOMER's own administration. Their
 *                             users, their roles, their operating units,
 *                             their audit trail, their subscription.
 *
 * They share no route, no controller and no permission. `isTenantAdmin()`
 * deliberately requires a tenant, so an operator does not silently become
 * an administrator of every customer, and `isPlatformAdmin()` is not
 * unioned into any gate here.
 *
 * WHAT THIS PAGE IS FOR. An HSE supervisor should never have to scroll
 * past subscription settings and user administration to reach a permit.
 * Admin Space exists so administration has somewhere to live that is not
 * inside somebody's operational day -- and so the person who does hold
 * those responsibilities has one place that states the organization's
 * actual administrative posture rather than seven tabs to reconcile.
 *
 * IT OWNS NO DATA AND DUPLICATES NO FORM. Every figure below is read from
 * the table that already holds it, and every action links to the existing
 * Settings tab, Activity Center or Billing page that already performs it
 * -- with their routes, validation and authorization completely unchanged.
 * That is why this release adds one route and not a second settings
 * module.
 */
class AdminSpaceController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        /*
         * The Administration Overview is for the tenant's ADMINISTRATOR,
         * not for everyone who can reach the space.
         *
         * `canAccessAdminSpace()` is deliberately wider (it includes HSE,
         * who has managed Departments and Positions from Settings since
         * v1.x and must not lose that), but this page states capacity,
         * billing state and the security posture of every account -- which
         * is the administrator's business. Entering a space is not a
         * permission; each page inside it asks its own question.
         */
        abort_unless(
            $user !== null && $user->isTenantAdmin(),
            403,
            'Administration Overview hanya untuk administrator perusahaan.'
        );

        $tenant = $user->tenant;
        $subscription = $tenant?->subscription;

        // Counted WITHOUT the tenant scope's help and with an explicit
        // tenant_id, mirroring EntitlementService::usersUsedCount(): these
        // are facts about the ORGANIZATION, and must not vary with which
        // operating units the viewer happens to be assigned to.
        $users = User::withoutGlobalScopes()->where('tenant_id', $user->tenant_id);

        return Inertia::render('Admin/Overview', [
            'organization' => [
                'name' => $tenant?->name,
                'slug' => $tenant?->slug,
                'status' => $tenant?->status,
                'created_at' => $tenant?->created_at,
                'is_demo' => (bool) $tenant?->is_demo,
            ],

            /*
             * ACCESS. The same capacity figures the customer's Billing page
             * and Master Admin both read, from
             * `Subscription::stateSnapshot()` and `EntitlementService` --
             * not a third calculation. v2.82.0 exists because those three
             * surfaces must never be able to disagree.
             */
            'access' => [
                'active_users' => $this->entitlements->usersUsedCount($tenant),
                'inactive_users' => (clone $users)->where('is_active', false)->count(),
                'total_users' => (clone $users)->count(),
                'included_users' => $this->entitlements->includedUserAllowance($tenant),
                'additional_users' => $this->entitlements->additionalUsersPurchased($tenant),
                'seat_limit' => $this->entitlements->userSeatLimit($tenant),
                'remaining_slots' => $this->entitlements->remainingUserSlots($tenant),
                'by_role' => (clone $users)
                    ->selectRaw('role, COUNT(*) as total')
                    ->groupBy('role')
                    ->pluck('total', 'role')
                    ->map(fn ($v) => (int) $v)
                    ->all(),
                'department_scoped' => (clone $users)->whereNotNull('department_key')->count(),
                'field_users' => (clone $users)->where('is_field_user', true)->count(),
                'ptw_granted' => (clone $users)->where('ptw_access', true)->count(),
            ],

            /*
             * SECURITY. Deliberately a POSTURE, not a score: each row is a
             * fact about the accounts that exist, and a number IOMS can
             * actually answer. There is no invented "security rating".
             */
            'security' => [
                'never_signed_in' => (clone $users)->whereNull('last_login_at')->count(),
                'signed_in_30_days' => (clone $users)->where('last_login_at', '>=', now()->subDays(30))->count(),
                'dormant_90_days' => (clone $users)
                    ->where('is_active', true)
                    ->where(fn ($q) => $q->whereNull('last_login_at')->orWhere('last_login_at', '<', now()->subDays(90)))
                    ->count(),
                'google_linked' => (clone $users)->whereNotNull('google_id')->count(),
                'password_only' => (clone $users)->whereNull('google_id')->count(),
                'unverified_email' => (clone $users)->whereNull('email_verified_at')->count(),
            ],

            'structure' => [
                'operating_units' => $this->entitlements->operatingUnitsUsedCount($tenant),
                'operating_unit_limit' => $this->entitlements->operatingUnitLimit($tenant),
                'active_operating_units' => Company::withoutGlobalScopes()
                    ->where('tenant_id', $user->tenant_id)->where('is_active', true)->count(),
                'departments' => Department::where('is_active', true)->count(),
                'positions' => Position::count(),
                // Custom roles the tenant created on top of the built-in
                // role column -- tenant-scoped by the same `tenant_id`
                // filter SettingsController already uses.
                'custom_roles' => Role::where('tenant_id', $user->tenant_id)->count(),
            ],

            /*
             * SUBSCRIPTION. `stateSnapshot()` is the single assembly point
             * for every lifecycle fact in IOMS (ADR 033 / 041), spread
             * whole rather than re-derived, so this panel cannot claim
             * "Active" while the customer's own Billing page says grace.
             */
            'subscription' => $subscription ? array_merge($subscription->stateSnapshot(), [
                'billing_mode' => $subscription->billingMode(),
                'billing_mode_label' => $subscription->billingModeLabel(),
            ]) : null,

            // The audit trail, newest first. The Activity Center is the
            // full, filterable surface -- this is the recent slice, so the
            // overview answers "has anything happened" without becoming a
            // second log viewer.
            'recent_activity' => ActivityLog::query()
                ->with('user:id,name')
                ->latest()
                ->limit(10)
                ->get(['id', 'user_id', 'action', 'description', 'subject_type', 'created_at'])
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'user' => $log->user?->name,
                    'created_at' => $log->created_at,
                ])->all(),
        ]);
    }
}
