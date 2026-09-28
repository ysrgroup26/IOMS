<?php

namespace App\Services;

use App\Models\Module;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;

/**
 * v1.11.0 (SaaS Finalization Pass). The single, central authority for
 * "is this tenant actually allowed to use the product/a given module
 * right now" -- deliberately does NOT duplicate `Tenant::modules()`/
 * `workspaces()` (the existing Platform-grant mechanism from Milestone 3,
 * UAT #4/#5) or `RestrictDepartmentAccess`/`config('departments')` (the
 * existing department-scope mechanism). It composes them:
 *
 *   Tenant entitlement (this service, NEW: subscription usability)
 *     AND
 *   Module/workspace grant (Tenant::modules()/workspaces(), EXISTING)
 *     AND
 *   Department scope (RestrictDepartmentAccess, EXISTING)
 *     AND
 *   Role capability (User::canManageX(), EXISTING)
 *   = access granted
 *
 * This class only ever answers the FIRST question. It never reimplements
 * the other three -- callers (HandleInertiaRequests, middleware) apply
 * all four independently, exactly as they already did before this class
 * existed, with this one added as an additional AND condition.
 */
class EntitlementService
{
    /**
     * v1.11.1 (Final Production Readiness Pass, Part 15): whether the
     * tenant's commercial record BLOCKS access right now -- only TRUE for
     * an explicitly `suspended`/`cancelled` Subscription (see
     * Subscription::isBlocked()'s own doc comment for why this changed
     * from the previous, stricter version). A tenant with NO Subscription
     * row at all (UNCONFIGURED -- shouldn't happen post-
     * TenantGrantSeeder/PlatformController::storeTenant(), but is a real
     * possibility for data that predates this feature) is deliberately
     * NOT blocked -- "missing commercial record" is far more likely to be
     * a data gap than an actual delinquent tenant, and blocking on it by
     * default is exactly the kind of stale-data-triggered lockout this
     * service exists to avoid. Same reasoning for an expired-but-not-
     * suspended record.
     */
    public function tenantIsUsable(?Tenant $tenant): bool
    {
        if (! $tenant) {
            return false;
        }

        // v2.70.0 -- the account-level switch, finally enforced.
        //
        // `tenants.status` had a Platform Admin UI that wrote a value
        // NOTHING read: suspending an organization did exactly nothing.
        // A control that claims to suspend a customer and does not is
        // worse than no control, so it is now read here, next to the
        // commercial state, and the two answer different questions:
        // `tenants.status` is the ACCOUNT (abuse, legal, a deliberate
        // shutdown), `subscriptions.status` is the COMMERCIAL
        // ARRANGEMENT. Either closing is enough to close the door.
        if (! $tenant->isActive()) {
            return false;
        }

        $subscription = $tenant->subscription;

        return $subscription === null || $subscription->isUsable();
    }

    /** Whether the tenant's Subscription is expired/unconfigured -- true even when NOT blocked, so the frontend can show a warning without hard-blocking anything. */
    public function tenantIsDegraded(?Tenant $tenant): bool
    {
        if (! $tenant) {
            return false;
        }

        $subscription = $tenant->subscription;

        return $subscription === null || $subscription->isDegraded();
    }

    /**
     * v2.70.0 -- where this organization sits in the subscription
     * lifecycle right now. Derived on every read; nothing stores it.
     *
     * A tenant with NO subscription row is treated as ACTIVE, not lapsed,
     * for the same reason every other method in this service is generous
     * about a missing commercial record: "no subscription on file" is far
     * more likely to be a data gap than a delinquent customer, and this
     * service exists to avoid stale-data lockouts, not to create them.
     */
    public function tenantLifecycleState(?Tenant $tenant): string
    {
        if (! $tenant) {
            return Subscription::LIFECYCLE_ACTIVE;
        }

        if (! $tenant->isActive()) {
            return Subscription::LIFECYCLE_SUSPENDED;
        }

        return $tenant->subscription?->lifecycleState() ?? Subscription::LIFECYCLE_ACTIVE;
    }

    /**
     * Whether this organization may still RECORD new work.
     *
     * False only while lapsed -- and a lapse withdraws writing, never
     * reading. See EnforceSubscriptionWriteAccess for why a read-only
     * lapse is the correct behaviour for a safety system of record.
     */
    public function tenantAllowsWrites(?Tenant $tenant): bool
    {
        return $this->tenantLifecycleState($tenant) !== Subscription::LIFECYCLE_LAPSED;
    }

    /** Whole days until the current period ends. Negative once past it; null when it never ends or there is no subscription. */
    public function daysUntilRenewal(?Tenant $tenant): ?int
    {
        return $tenant?->subscription?->daysUntilPeriodEnd();
    }

    /**
     * v2.13.0 (SaaS Phase 1 -- Subscription Architecture & Entitlement
     * Enforcement). "Ungranted" safety net, the missing piece that makes
     * `config('saas.enforce_workspace_entitlement')` finally safe to turn
     * on. Before this pass, a tenant with ZERO rows in `tenant_modules`/
     * `tenant_workspaces` (never explicitly provisioned -- the exact
     * "legacy tenant predates this feature" case Part 25 of this phase's
     * own directive asks to handle safely) would fail EVERY
     * `tenantCanUseModule()`/`tenantCanUseWorkspace()` check once
     * enforcement is enabled, i.e. total lockout -- the opposite of
     * today's actual behavior (unenforced, so an ungranted tenant
     * currently reaches everything its role/department allows). That is
     * exactly the "Do NOT simply deny everyone" failure this phase's
     * directive explicitly forbids.
     *
     * Fixed by treating "this tenant has no grant rows for this kind of
     * entitlement at all" as fully granted (skip the allow-list check
     * entirely) -- a genuinely PROVISIONED tenant (has at least one row,
     * whether from `PlatformController::storeTenant()`'s package-derived
     * sync or `TenantGrantSeeder`'s "grant everything" seed) is
     * unaffected and still uses the real allow-list. This is not a
     * bypass for a chosen tenant -- it's a uniform, auditable default
     * ("no explicit grants recorded yet" = "not yet restricted") applied
     * identically to every tenant, and it disappears the moment any
     * grant row exists for that tenant, at which point the real
     * allow-list takes over completely (including correctly DENYING
     * anything not in that tenant's own granted set).
     */
    public function tenantCanUseModule(?Tenant $tenant, string $moduleKey): bool
    {
        if (! $this->tenantIsUsable($tenant)) {
            return false;
        }

        if (! $tenant->modules()->exists()) {
            return true;
        }

        return $tenant->modules()->where('key', $moduleKey)->exists();
    }

    /** Same as tenantCanUseModule() but for a workspace key -- see Tenant::workspaces() and this method's own doc comment above for the "ungranted tenant" safety net. */
    public function tenantCanUseWorkspace(?Tenant $tenant, string $workspaceKey): bool
    {
        if (! $this->tenantIsUsable($tenant)) {
            return false;
        }

        // v2.58.0: a GLOBAL-tier workspace is never withheld by a plan.
        // Reports and Administration are the application's own chrome, not
        // sold capacity -- see Workspace::globalKeys(). Without this a
        // Starter or Professional tenant was 403'd out of Reports,
        // Analytics, Report Center and Audit Logs, because both keys
        // appear in config('departments') and this check is enabled by
        // default.
        if (Workspace::isGlobalKey($workspaceKey)) {
            return true;
        }

        if (! $tenant->workspaces()->exists()) {
            return true;
        }

        return $tenant->workspaces()->where('key', $workspaceKey)->exists();
    }

    /**
     * v2.58.0 -- THE ONE ANSWER TO "WHICH WORKSPACES DOES THIS TENANT
     * HAVE", used by the navigation layer as well as the route gate.
     *
     * It exists because the two layers had drifted into contradicting each
     * other. `tenantCanUseWorkspace()` treats "this tenant has no grant
     * rows at all" as UNRESTRICTED -- a deliberate, documented decision so
     * a tenant provisioned before grants existed is never locked out of
     * its own product. HandleInertiaRequests independently implemented the
     * opposite: it plucked the grant rows and forced every workspace not
     * in that list to `is_active: false`, so an empty list hid the ENTIRE
     * sidebar. The same tenant was therefore allowed through every route
     * and shown no navigation to reach them with.
     *
     * Both callers now ask here, so they cannot disagree again.
     */
    public function grantedWorkspaceKeys(?Tenant $tenant): array
    {
        $global = Workspace::globalKeys();

        if (! $tenant) {
            return $global;
        }

        $granted = $tenant->workspaces()->pluck('key')->all();

        // No grants recorded == not yet restricted, exactly as
        // tenantCanUseWorkspace() reads it.
        if ($granted === []) {
            return Workspace::query()->pluck('key')->all();
        }

        return array_values(array_unique([...$granted, ...$global]));
    }

    /**
     * v2.83.0 -- THE DEPARTMENT WORKSPACES ONE PERSON MAY ACTUALLY REACH.
     *
     * Three independent gates, composed here so the workspace SWITCHER
     * and the workspace-FOCUS validator ask one question instead of two
     * that can drift -- the same drift `grantedWorkspaceKeys()` itself was
     * written to end in v2.58.0.
     *
     *   plan        the tenant is granted the workspace
     *   tier        it is a department, not the application's own chrome
     *   assignment  a Department User has exactly one, and it is theirs
     *
     * It answers a NAVIGATION question and is never the only gate on a
     * request: `EnforceTenantEntitlement` and each controller's own
     * capability check are unchanged and remain the real boundary.
     */
    public function authorizedDepartmentKeys(?User $user): array
    {
        if (! $user || $user->tenant_id === null) {
            return [];
        }

        $granted = $this->grantedWorkspaceKeys($user->tenant);

        $departments = Workspace::query()
            ->where('tier', Workspace::TIER_DEPARTMENT)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('key')
            ->filter(fn (string $key) => in_array($key, $granted, true))
            ->values()
            ->all();

        // A Department User is assigned to exactly one. If the assignment
        // names something their plan does not grant, the answer is an
        // empty list rather than a fallback -- silently substituting a
        // different department would be inventing an entitlement.
        if ($user->department_key) {
            return array_values(array_intersect($departments, [$user->department_key]));
        }

        return $departments;
    }

    /**
     * v2.83.0 -- the focus this account should actually be given, which is
     * not always the one stored on the row.
     *
     * A focus is a PREFERENCE, and a preference can go stale: the plan is
     * downgraded, the workspace is deactivated, the person is reassigned.
     * Every one of those has to degrade to "All Workspaces" rather than
     * strand somebody in a workspace they can no longer reach -- a focus
     * must never be able to withhold access, in either direction.
     *
     * Null means All Workspaces, which is also what every account had
     * before this column existed.
     */
    public function effectiveWorkspaceFocus(?User $user): ?string
    {
        $focus = $user?->workspace_focus;

        if ($focus === null) {
            return null;
        }

        return in_array($focus, $this->authorizedDepartmentKeys($user), true) ? $focus : null;
    }

    /** The module equivalent, with the same "no grants recorded == unrestricted" rule. */
    public function grantedModuleKeys(?Tenant $tenant): array
    {
        if (! $tenant) {
            return [];
        }

        $granted = $tenant->modules()->pluck('key')->all();

        return $granted === [] ? Module::query()->pluck('key')->all() : $granted;
    }

    /**
     * v2.17.0 (PTW Field Workflow Foundation + Controlled PTW Access,
     * Part 5/22). The tenant's current package's PTW-enabled-user
     * ceiling -- `null` means unlimited/custom (Enterprise), matching
     * `max_users`/`max_companies`'s existing null-means-unlimited
     * convention on the same `packages` table. A tenant with no
     * subscription/package on record gets `null` (unlimited) rather than
     * `0` -- the same "don't silently deny an unconfigured tenant"
     * principle `EntitlementService`'s other methods already use (see
     * `tenantCanUseModule()`'s own doc comment), not a special case
     * invented for this method.
     */
    public function ptwUserQuota(?Tenant $tenant): ?int
    {
        // v2.53.0 -- RETIRED AS A SOLD CAPACITY.
        //
        // PTW Access used to be a second seat pool alongside `max_users`,
        // which made every plan card read as two numbers a buyer had to
        // reconcile. Capacity is now expressed as TOTAL USERS, and PTW
        // Access went back to being what it always was operationally: an
        // internal permission an administrator grants to an account that
        // already exists.
        //
        // This deliberately still returns null (= no ceiling) rather than
        // being deleted, so any caller that has not been updated fails
        // OPEN on a limit that is no longer sold rather than fatally. The
        // AUTHORIZATION is untouched -- see User::canCreatePtw() and
        // PermitToWorkController's own gate.
        return null;
    }

    /**
     * v2.38.0 (Master Audit, P1 -- CONFIRMED unenforced entitlement).
     *
     * The commercial rule is `max_ptw_users <= max_users`: a Starter
     * tenant with 15 seats and 15 PTW seats may hold at most 15 accounts.
     * The PTW half of that rule was carefully enforced (see
     * `canEnablePtwAccess()` and its caller's row-locking transaction).
     * The PARENT half was not enforced anywhere at all:
     * `Subscription::seatLimit()` was only ever READ for display
     * (PlatformController's tenant list, Settings' subscription panel)
     * and validated as an input when a Platform Admin edits a plan --
     * never checked when a tenant actually creates a user.
     *
     * Effect: any tenant could create unlimited accounts regardless of
     * the plan they pay for -- direct revenue leakage in a seat-based
     * SaaS, and it broke the subset invariant from the parent side (a
     * tenant could hold 500 accounts while their plan sold 15).
     *
     * `seatLimit()` (not `package->max_users`) is deliberately the source
     * of truth here, because a Platform Admin can override seats per
     * tenant via `subscriptions.seat_limit`; reading the package
     * directly would silently ignore that override. Null still means
     * unlimited, matching every other quota in this service.
     */
    public function userSeatLimit(?Tenant $tenant): ?int
    {
        return $tenant?->subscription?->seatLimit();
    }

    /**
     * v2.82.0 -- ACTIVE LOGIN ACCOUNTS. The decision this method has been
     * waiting for has been made.
     *
     * It used to count every account, active or not, and said so: "if the
     * business would rather free a seat on deactivation, this one method
     * is the only place that has to change". The approved model is
     * ACTIVE users, so a deactivated account no longer consumes capacity
     * and no longer costs anything.
     *
     * WHAT A USER IS, precisely, because three plausible answers are all
     * wrong:
     *
     *   NOT a device. One account may sign in from a phone, a tablet and
     *   a site terminal; that is one user. There are deliberately no
     *   device seats anywhere in IOMS.
     *
     *   NOT an employee record. Most employees in a yard never log in.
     *   `employees` and `users` stay separate tables for exactly this
     *   reason, and nothing here counts the former.
     *
     *   NOT a role. A Tenant Admin is a capability on an account, not a
     *   second kind of seat, and is counted once like anybody else.
     *
     * Deactivating an account is therefore the supported way to release
     * capacity without deleting a person's history -- which is what a
     * system of record for safety compliance has to allow.
     */
    public function usersUsedCount(?Tenant $tenant): int
    {
        if (! $tenant) {
            return 0;
        }

        return User::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->count();
    }

    /** The plan allowance alone, before anything the tenant purchased. */
    public function includedUserAllowance(?Tenant $tenant): ?int
    {
        return $tenant?->subscription?->includedUsers();
    }

    /** Paid capacity beyond the plan. */
    public function additionalUsersPurchased(?Tenant $tenant): int
    {
        return $tenant?->subscription?->additionalUsers() ?? 0;
    }

    /**
     * How many more ACTIVE accounts this tenant may create before they
     * have to buy capacity. Null means unlimited.
     */
    public function remainingUserSlots(?Tenant $tenant): ?int
    {
        $limit = $this->userSeatLimit($tenant);

        return $limit === null ? null : max(0, $limit - $this->usersUsedCount($tenant));
    }

    /**
     * How many additional users a tenant would have to BUY to hold this
     * many active accounts. Zero when the allowance already covers it.
     *
     * Server-side, from the tenant's own subscription -- never from a
     * quantity a browser submitted, which is the whole point of computing
     * it here rather than in the page that shows the price.
     */
    public function additionalUsersRequiredFor(?Tenant $tenant, int $activeUsers): int
    {
        $included = $this->includedUserAllowance($tenant);

        return $included === null ? 0 : max(0, $activeUsers - $included);
    }

    /**
     * Server-side gate for creating a new user account. Mirrors
     * `canEnablePtwAccess()` exactly, including the null-means-unlimited
     * convention, so both halves of the seat rule behave identically.
     */
    public function canCreateUser(?Tenant $tenant): bool
    {
        $limit = $this->userSeatLimit($tenant);

        return $limit === null || $this->usersUsedCount($tenant) < $limit;
    }

    /** How many of this tenant's OWN users currently have `ptw_access = true` right now. Tenant-scoped via `tenant_id` -- Tenant A can never consume Tenant B's quota. */
    public function ptwUsersUsedCount(?Tenant $tenant): int
    {
        if (! $tenant) {
            return 0;
        }

        return User::where('tenant_id', $tenant->id)->where('ptw_access', true)->count();
    }

    /**
     * The actual server-side gate `SettingsController::updatePtwAccess()`
     * enforces before flipping a user's `ptw_access` from false to true
     * -- never trusts a client-supplied count or limit (Part 6's own
     * "do not trust client-provided counts/limits" instruction). A null
     * quota (unlimited/custom, or an unconfigured tenant) always allows.
     */
    public function canEnablePtwAccess(?Tenant $tenant): bool
    {
        // v2.53.0: always true. There is no PTW seat allowance to exhaust
        // any more (see ptwUserQuota()). Whether a given user MAY create a
        // permit is still decided by User::canCreatePtw() and enforced
        // server-side by PermitToWorkController -- that is authorization,
        // and it is unchanged.
        return true;
    }

    /**
     * v2.54.0 -- OPERATING UNIT CAPACITY, finally enforced.
     *
     * `packages.max_companies` is how many OPERATING UNITS an
     * organization may run (Starter 1, Professional 2, Enterprise
     * unlimited). Until now it was read for DISPLAY only -- the Settings
     * billing panel drew a meter from it and the pricing page printed it
     * -- while `SettingsController::storeCompanyEntity()` created
     * operating units with no check at all. Exactly the shape of the
     * revenue leak v2.38.0 found on user seats, in the other quota.
     *
     * Null still means unlimited, and a tenant with no subscription or no
     * package on record gets null rather than 0 -- the same
     * "don't silently deny an unconfigured tenant" rule every other
     * method in this service follows.
     */
    public function operatingUnitLimit(?Tenant $tenant): ?int
    {
        return $tenant?->subscription?->package?->max_companies;
    }

    /**
     * Every operating unit belonging to this organization, active or not.
     *
     * Deliberately counts WITHOUT global scopes and filters on tenant_id
     * directly, mirroring usersUsedCount(). Company carries
     * CompanyAuthorizationScope, so `Company::count()` would return what
     * the CURRENT USER may see -- an administrator restricted to one unit
     * would count 1 and be allowed to create past the plan limit. A quota
     * is a property of the organization, never of the person asking.
     */
    public function operatingUnitsUsedCount(?Tenant $tenant): int
    {
        if (! $tenant) {
            return 0;
        }

        return \App\Models\Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
    }

    /** Server-side gate for adding an operating unit. Same null-means-unlimited convention as canCreateUser(). */
    public function canCreateOperatingUnit(?Tenant $tenant): bool
    {
        $limit = $this->operatingUnitLimit($tenant);

        return $limit === null || $this->operatingUnitsUsedCount($tenant) < $limit;
    }

    /**
     * A short, user-facing reason string for why access is currently
     * blocked -- used by the frontend to distinguish "not built yet"
     * from "not included in your plan" from "subscription issue", per
     * the explicit product requirement that those three must never look
     * identical to a user.
     */
    public function blockedReason(?Tenant $tenant): ?string
    {
        if (! $tenant) {
            return 'no_tenant';
        }

        // v2.70.0: an account-level suspension is reported as its own
        // reason, so support can tell "we switched this account off" apart
        // from "their subscription lapsed" without opening the database.
        if (! $tenant->isActive()) {
            return 'account_suspended';
        }

        $subscription = $tenant->subscription;

        if (! $subscription) {
            return 'no_subscription';
        }

        // v2.70.0: the derived lifecycle, not the stored status. `grace`
        // and `lapsed` are real, distinguishable situations a customer
        // must be told apart -- "you have days left" versus "new records
        // are paused" -- and neither was expressible before.
        return match ($subscription->lifecycleState()) {
            Subscription::LIFECYCLE_SUSPENDED => 'suspended',
            Subscription::LIFECYCLE_CANCELLED => 'cancelled',
            Subscription::LIFECYCLE_GRACE => $subscription->status === Subscription::STATUS_TRIAL ? 'trial_expired' : 'grace',
            Subscription::LIFECYCLE_LAPSED => $subscription->status === Subscription::STATUS_TRIAL ? 'trial_expired' : 'lapsed',
            default => null,
        };
    }
}
