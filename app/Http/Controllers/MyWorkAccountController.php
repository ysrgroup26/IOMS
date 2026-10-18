<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use App\Rules\InCurrentTenant;
use App\Services\MyWorkAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * v2.123.0 -- CREATING AND MANAGING MY WORK LOGINS.
 *
 * Deliberately its own controller rather than three more methods on
 * SettingsController, because the two flows answer different questions and
 * mixing them is the defect this release exists to fix:
 *
 *   Add Platform User   invents an identity (name, email, password) and
 *                       grants it a ROLE and a DEPARTMENT. It is a person
 *                       who uses the IOMS dashboard.
 *   Add My Work User    PICKS an identity that already exists in the
 *                       Employee Register and issues it an access code. It
 *                       is a worker who uses My Work, and asking an
 *                       administrator to retype a name and a job title that
 *                       the register already holds is how a second,
 *                       diverging copy of a person gets created.
 *
 * WHAT IS SHARED AND MUST STAY SHARED: the seat quota (both classes go
 * through EntitlementService::canCreateUserOfType, re-counted under a row
 * lock), the tenant boundary, the account classification, and
 * RestrictMyWorkUser. Separating the FORMS must not become a way around
 * any of them -- the quota check lives in MyWorkAccessService::createFor(),
 * inside the same transaction that writes the row.
 */
class MyWorkAccountController extends Controller
{
    public function __construct(private readonly MyWorkAccessService $access) {}

    /**
     * Create the login for one employee and hand back its access code.
     *
     * The code is returned in the SESSION, once, for the panel that shows
     * it. Nothing persists it -- see MyWorkAccessService::resetCode().
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManagement($request);

        $validated = $request->validate([
            'employee_id' => ['required', new InCurrentTenant('employees')],
            /*
             * OPTIONAL, and that is the point: a yard worker may have no
             * work address at all. When one is given it still has to be
             * unique, because it remains a way to reach the account.
             */
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
        ]);

        $employee = Employee::query()->findOrFail($validated['employee_id']);

        $result = $this->access->createFor($employee, $request->user(), $validated['email'] ?? null);

        return back()->with('success', 'My Work access created.')->with('my_work_access', [
            'user_id' => $result['user']->id,
            'employee_name' => $employee->full_name,
            'employee_number' => $employee->employee_id,
            'code' => $result['code'],
        ]);
    }

    /** Issue a new code, invalidating the one the worker holds. */
    public function resetAccessCode(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManagement($request);
        $this->authorizeAccount($request, $user);

        $code = $this->access->resetCode($user);

        return back()->with('success', 'Access code reset.')->with('my_work_access', [
            'user_id' => $user->id,
            'employee_name' => $user->employee?->full_name ?? $user->displayName(),
            'employee_number' => $user->employee?->employee_id,
            'code' => $code,
            'reset' => true,
        ]);
    }

    /**
     * Suspend or restore the login without destroying it.
     *
     * Deactivating is the ordinary answer for somebody off site or between
     * contracts: the account stops authenticating (the login handler
     * refuses an inactive account, and EntitlementService counts only
     * active ones, so the seat is released) and its link to the register
     * and its history survive.
     */
    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $this->authorizeManagement($request);
        $this->authorizeAccount($request, $user);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        /*
         * Reactivating consumes a seat again, so it is asked the same
         * question creating one is. Without this, deactivating an account
         * at a full quota and reactivating it later would walk the tenant
         * past its allowance one account at a time.
         */
        if ($validated['is_active'] && ! $user->is_active) {
            $entitlements = app(\App\Services\EntitlementService::class);

            if (! $entitlements->canCreateUserOfType($request->user()->tenant, User::TYPE_MY_WORK)) {
                return back()->withErrors([
                    'is_active' => sprintf(
                        'Kuota My Work User paket Anda sudah penuh (%d dari %d terpakai), sehingga akun ini belum dapat diaktifkan kembali.',
                        $entitlements->myWorkUsersUsedCount($request->user()->tenant),
                        (int) $entitlements->myWorkSeatLimit($request->user()->tenant),
                    ),
                ]);
            }
        }

        $user->update(['is_active' => $validated['is_active']]);

        ActivityLog::record(
            'updated',
            'My Work access for '.($user->employee?->full_name ?? $user->displayName()).' was '
                .($validated['is_active'] ? 'reactivated' : 'deactivated').'.',
            $user
        );

        return back()->with('success', $validated['is_active'] ? 'Access reactivated.' : 'Access deactivated.');
    }

    /**
     * The same gate Users & Access itself uses (`manage_system`, the prop that renders User Management). The route group already
     * requires `role:super_admin`; this is the second layer the house style
     * asks for, and it is what keeps the check true if the route ever moves.
     */
    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()?->canManageSystemSettings(), 403);
    }

    /**
     * A My Work account of THIS tenant, and nothing else.
     *
     * `role:super_admin` restricts by role, never by tenant -- the same
     * hole Milestone 3 found on updateUser(), which let an administrator
     * act on another tenant's account by id. 404 rather than 403: whether
     * another tenant's account exists is not this administrator's business.
     */
    private function authorizeAccount(Request $request, User $user): void
    {
        abort_unless($user->tenant_id === $request->user()->tenant_id, 404);
        abort_unless($user->isMyWorkUser(), 404);
    }
}
