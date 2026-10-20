<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PermitToWork;
use App\Models\PtwWorkflowSetting;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * v2.127.0 -- THE TWO GATES ON A PERMIT, AND WHO HOLDS THEM.
 *
 * A Permit to Work now passes through two decisions by two different
 * people: HSE VERIFIES that the work is safe to authorise, and an approver
 * APPROVES it. Only then may it become ACTIVE.
 *
 * Every question about those gates is answered here, and only here:
 *
 *   - may this person verify / approve this permit?
 *   - who should be told that a permit is waiting for them?
 *   - what happens for a company that has configured nothing?
 *
 * ONE SOURCE, ASKED BY BOTH SIDES. The controller gates the write with
 * `canVerify()` / `canApprove()`, and the page decides whether to OFFER
 * the button with the same two methods, shared as props. That is ADR 061's
 * rule: anything that offers an action asks the same question the gate
 * that would refuse it asks, from the same source. A Verify button that
 * answers 403 reads to the person as a broken account.
 *
 * AND THE NOTIFICATION ASKS IT TOO. The recipients of each stage are
 * derived from the same configuration as the authority, so the person who
 * is asked to act is always somebody who may act. Telling a manager to
 * verify a permit they cannot verify is the same defect wearing a
 * different hat.
 */
class PtwWorkflowService
{
    public const STAGE_VERIFY = 'verify';

    public const STAGE_APPROVE = 'approve';

    /**
     * What a company that has configured nothing gets.
     *
     * NOT an empty list. An empty configuration would mean "nobody may
     * approve anything", which would silently stop every yard that has not
     * yet visited Settings -- an upgrade that breaks working installations
     * is worse than one that defers a feature.
     *
     * So the defaults reproduce the behaviour PTW had before this release:
     * verification by whoever could already approve a permit
     * (`canManageHse()` = super_admin + hse), and final approval by the
     * product's existing approver list (`config('workflow.approvers')` =
     * super_admin + manager), which is the same list Material Request has
     * used since v1.6.9.1.
     *
     * @return array{verify_roles: list<string>, approve_roles: list<string>}
     */
    public function defaults(): array
    {
        return [
            'verify_roles' => [User::ROLE_SUPER_ADMIN, User::ROLE_HSE],
            'approve_roles' => array_values(array_unique(
                array_merge([User::ROLE_SUPER_ADMIN], config('workflow.approvers', []))
            )),
        ];
    }

    /**
     * The configuration in force for a company, stored or defaulted.
     *
     * Read through the global scope deliberately: a caller handing in
     * another tenant's company id gets nothing back and therefore gets the
     * defaults, never that tenant's routing.
     *
     * @return array{verify_roles: list<string>, verify_user_ids: list<int>, approve_roles: list<string>, approve_user_ids: list<int>, configured: bool}
     */
    public function settingsFor(?int $companyId): array
    {
        $defaults = $this->defaults();

        $row = $companyId === null
            ? null
            : PtwWorkflowSetting::where('company_id', $companyId)->first();

        if ($row === null) {
            return [
                'verify_roles' => $defaults['verify_roles'],
                'verify_user_ids' => [],
                'approve_roles' => $defaults['approve_roles'],
                'approve_user_ids' => [],
                'configured' => false,
            ];
        }

        /*
         * A stage the company left entirely empty falls back to the
         * default rather than locking itself out. Saving a row for ONE
         * stage must not silently disable the other -- which is what a
         * bare `?? []` here would do.
         */
        $verifyRoles = array_values(array_filter((array) ($row->verify_roles ?? [])));
        $verifyUsers = array_values(array_filter(array_map('intval', (array) ($row->verify_user_ids ?? []))));
        $approveRoles = array_values(array_filter((array) ($row->approve_roles ?? [])));
        $approveUsers = array_values(array_filter(array_map('intval', (array) ($row->approve_user_ids ?? []))));

        return [
            'verify_roles' => ($verifyRoles === [] && $verifyUsers === []) ? $defaults['verify_roles'] : $verifyRoles,
            'verify_user_ids' => $verifyUsers,
            'approve_roles' => ($approveRoles === [] && $approveUsers === []) ? $defaults['approve_roles'] : $approveRoles,
            'approve_user_ids' => $approveUsers,
            'configured' => true,
        ];
    }

    /**
     * May this person perform this stage on this permit?
     *
     * Three things must all be true, and the FIRST is the one most easily
     * forgotten: the permit must belong to a company this user's tenant
     * owns. A role check alone would let an HSE officer at one customer
     * verify another customer's permit, because `hse` is a role every
     * tenant has.
     */
    public function canAct(User $user, PermitToWork $permit, string $stage): bool
    {
        if (! $this->sharesTenantWith($user, $permit)) {
            return false;
        }

        $settings = $this->settingsFor($permit->company_id);

        $roles = $stage === self::STAGE_VERIFY ? $settings['verify_roles'] : $settings['approve_roles'];
        $userIds = $stage === self::STAGE_VERIFY ? $settings['verify_user_ids'] : $settings['approve_user_ids'];

        /*
         * The UNION of the two, which is what makes "roles or named
         * people" a usable configuration: a company may name a role, name
         * individuals, or both. A named individual does NOT need to hold
         * the role -- naming somebody is the company saying so explicitly,
         * and requiring both would make the user list decorative.
         */
        return in_array($user->role, $roles, true) || in_array((int) $user->id, $userIds, true);
    }

    public function canVerify(User $user, PermitToWork $permit): bool
    {
        return $this->canAct($user, $permit, self::STAGE_VERIFY);
    }

    public function canApprove(User $user, PermitToWork $permit): bool
    {
        return $this->canAct($user, $permit, self::STAGE_APPROVE);
    }

    /**
     * Who should be told that a permit is waiting at this stage.
     *
     * Derived from the SAME configuration as the authority, so a
     * notification can never ask somebody for a decision they are not
     * allowed to make.
     *
     * Scoped to the permit's own TENANT, not to its company: a yard's
     * permits are routinely approved by a manager who sits at head office
     * and belongs to no single operating unit, and scoping recipients to
     * `company_id` would silently exclude exactly that person. Tenant is
     * the real boundary, and `User`'s own global scope enforces it.
     *
     * @return Collection<int, User>
     */
    public function recipientsFor(PermitToWork $permit, string $stage): Collection
    {
        $settings = $this->settingsFor($permit->company_id);

        $roles = $stage === self::STAGE_VERIFY ? $settings['verify_roles'] : $settings['approve_roles'];
        $userIds = $stage === self::STAGE_VERIFY ? $settings['verify_user_ids'] : $settings['approve_user_ids'];

        $tenantId = $this->tenantIdOf($permit);

        if ($tenantId === null) {
            return collect();
        }

        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            /*
             * A My Work account is a field login (ADR 062). It may RAISE a
             * permit; it does not sign one, and `RestrictMyWorkUser`
             * refuses it the pages an approval decision is made on. Telling
             * it to go and approve something would be an offer the server
             * answers 403 for.
             */
            ->where('user_type', '!=', User::TYPE_MY_WORK)
            ->where(function ($query) use ($roles, $userIds) {
                $query->whereIn('role', $roles ?: ['__none__']);

                if ($userIds !== []) {
                    $query->orWhereIn('id', $userIds);
                }
            })
            ->get();
    }

    /** The stage a permit is currently waiting at, or null when it waits for nobody. */
    public function pendingStage(PermitToWork $permit): ?string
    {
        return match ($permit->status) {
            PermitToWork::STATUS_SUBMITTED => self::STAGE_VERIFY,
            PermitToWork::STATUS_VERIFIED => self::STAGE_APPROVE,
            default => null,
        };
    }

    /**
     * The roles a company may choose from, for the Settings UI.
     *
     * Deliberately excludes the platform roles: `platform_admin` and
     * `account` are not operational staff and must never appear as an
     * approver a customer can pick.
     *
     * @return array<string, string> role => label
     */
    public function assignableRoles(): array
    {
        return [
            User::ROLE_SUPER_ADMIN => 'Super Admin',
            User::ROLE_HSE => 'HSE',
            User::ROLE_MANAGER => 'Manager',
            User::ROLE_HRD => 'HRD',
            User::ROLE_WAREHOUSE => 'Warehouse',
        ];
    }

    private function sharesTenantWith(User $user, PermitToWork $permit): bool
    {
        $tenantId = $this->tenantIdOf($permit);

        return $tenantId !== null && (int) $user->tenant_id === $tenantId;
    }

    /** The tenant a permit belongs to, through its operating unit. */
    private function tenantIdOf(PermitToWork $permit): ?int
    {
        $companyId = $permit->company_id;

        if ($companyId === null) {
            return null;
        }

        $tenantId = Company::withoutGlobalScopes()->whereKey($companyId)->value('tenant_id');

        return $tenantId === null ? null : (int) $tenantId;
    }
}
