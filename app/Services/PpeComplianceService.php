<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\PpeType;
use Illuminate\Support\Collection;

/**
 * v2.121.0 -- IS THIS WORKER ACTUALLY EQUIPPED.
 *
 * IOMS had no answer to that. It could say whether ONE ITEM was active,
 * expiring or expired (`EmployeePpe::getEffectiveStatusAttribute()`), and
 * it could count employees with no PPE record at all -- but nothing read
 * a person's records together and said whether they were equipped to go
 * to work. The approved rule:
 *
 *   WORK CLOTHING -- a Work Uniform OR a Coverall. Either satisfies the
 *                    group; nobody needs both.
 *   SAFETY SHOES  -- required.
 *   SAFETY HELMET -- required.
 *
 * Safety Glasses, Harness and Headlamp are recorded and never required.
 *
 * THREE STATES, NOT TWO, and that is the owner's decision rather than a
 * convenience:
 *
 *   compliant          all three groups held, nothing expired
 *   needs_replacement  all three groups held, at least one expired
 *   incomplete         a group has no issuance record at all
 *
 * Collapsing the middle one into "compliant" would print Aman beside a
 * pair of boots whose service life ended two years ago. Collapsing it
 * into "incomplete" would hide the difference between somebody who was
 * never issued equipment and somebody whose equipment is simply due --
 * two different problems with two different actions, and HSE has to be
 * able to tell them apart in a list.
 *
 * WHICH TYPE SATISFIES WHICH GROUP IS READ FROM THE TYPE'S DECLARED ROLE,
 * never from its name. ADR 050 lets a customer rename a type through an
 * override, so a rule matching the literal string "Safety Shoes" would
 * report the whole workforce of any tenant who renamed theirs as
 * non-compliant. See `PpeType::effectiveComplianceRole()`.
 */
class PpeComplianceService
{
    public const COMPLIANT = 'compliant';

    public const NEEDS_REPLACEMENT = 'needs_replacement';

    public const INCOMPLETE = 'incomplete';

    /**
     * Type id => the role it satisfies, for one company's effective
     * catalogue. Cached per company for the life of the request, because
     * every employee in a list asks the same question.
     *
     * @var array<int, array<int, string>>
     */
    private array $roleMapCache = [];

    /**
     * @return array<int, string> ppe_type_id => role
     */
    public function roleMapForCompany(?int $companyId): array
    {
        $key = $companyId ?? 0;

        if (isset($this->roleMapCache[$key])) {
            return $this->roleMapCache[$key];
        }

        /*
         * Every type this company could have issued, INCLUDING inactive
         * ones and including built-ins it has since overridden. A
         * deactivated type does not retract the equipment somebody is
         * already wearing, and an employee issued the built-in before an
         * override existed still holds that row -- the same family problem
         * `EmployeePpeImport` documents. So the map is deliberately wider
         * than "what you may issue today", which is
         * `effectiveForCompany()`'s question, not this one.
         */
        $types = PpeType::withoutGlobalScopes()
            ->when($companyId !== null, fn ($q) => $q->where(
                fn ($inner) => $inner->whereNull('company_id')->orWhere('company_id', $companyId)
            ))
            ->get(['id', 'company_id', 'source_default_id', 'compliance_role']);

        $byId = $types->keyBy('id');
        $map = [];

        foreach ($types as $type) {
            // Resolve the inheritance in memory rather than per row, with
            // the same sentinel rule PpeType::effectiveComplianceRole()
            // applies -- the two must never disagree about a role.
            $role = $this->resolveRole($type->compliance_role, $type->source_default_id, $byId);

            if ($role !== null) {
                $map[(int) $type->id] = $role;
            }
        }

        return $this->roleMapCache[$key] = $map;
    }

    /**
     * One row's role: its own, or its template's when it has never been
     * classified, with `additional` meaning explicitly not a requirement.
     *
     * The single copy of that rule on the read side, mirroring
     * `PpeType::effectiveComplianceRole()`. Two places compute it because
     * one works per model and one works over a loaded set; they must give
     * the same answer, which is what `PpeComplianceRoleTest` pins.
     *
     * @param  \Illuminate\Support\Collection<int, PpeType>  $byId
     */
    private function resolveRole(?string $own, ?int $templateId, $byId): ?string
    {
        if ($own === PpeType::ROLE_ADDITIONAL) {
            return null;
        }

        if ($own !== null) {
            return $own;
        }

        if ($templateId === null) {
            return null;
        }

        $inherited = $byId->get($templateId)?->compliance_role;

        return $inherited === PpeType::ROLE_ADDITIONAL ? null : $inherited;
    }

    /**
     * The status of one employee, from records already loaded where
     * possible.
     *
     * @param  Collection<int, EmployeePpe>|null  $assignments  pass the employee's
     *                                                          records to avoid a query; omit to have them read here
     * @return array{status: string, groups: array<string, array{held: bool, expired: bool, issued_date: ?string, expiry_date: ?string, type: ?string}>}
     */
    public function evaluate(Employee $employee, ?Collection $assignments = null): array
    {
        $roles = $this->roleMapForCompany($employee->company_id);

        $assignments ??= EmployeePpe::withoutGlobalScopes()
            ->with('ppeType:id,name')
            ->where('employee_id', $employee->id)
            ->whereIn('status', EmployeePpe::IN_SERVICE_STATUSES)
            ->get();

        $groups = [];

        foreach (PpeType::COMPLIANCE_ROLES as $role) {
            $groups[$role] = ['held' => false, 'expired' => false, 'issued_date' => null, 'expiry_date' => null, 'type' => null];
        }

        foreach ($assignments as $assignment) {
            /*
             * Only equipment still genuinely in the person's possession
             * counts. An archived or replaced row is history, and history
             * does not keep somebody equipped.
             */
            if (! in_array($assignment->status, EmployeePpe::IN_SERVICE_STATUSES, true)) {
                continue;
            }

            $role = $roles[(int) $assignment->ppe_type_id] ?? null;

            if ($role === null) {
                continue; // additional equipment, never required
            }

            $expired = $assignment->isExpired();
            $current = $groups[$role];

            /*
             * A group is satisfied by its BEST record, not its latest. Two
             * pairs of boots -- one expired, one current -- is an equipped
             * worker, so a valid record must not be shadowed by an expired
             * one that happens to be issued later.
             */
            $better = ! $current['held']
                || ($current['expired'] && ! $expired)
                || ($current['expired'] === $expired
                    && $assignment->issued_date
                    && (! $current['issued_date'] || $assignment->issued_date->toDateString() > $current['issued_date']));

            if ($better) {
                $groups[$role] = [
                    'held' => true,
                    'expired' => $expired,
                    'issued_date' => $assignment->issued_date?->toDateString(),
                    'expiry_date' => $assignment->expiry_date?->toDateString(),
                    'type' => $assignment->ppeType?->name,
                ];
            }
        }

        return ['status' => $this->statusFromGroups($groups), 'groups' => $groups];
    }

    /** @param array<string, array{held: bool, expired: bool}> $groups */
    private function statusFromGroups(array $groups): string
    {
        foreach ($groups as $group) {
            if (! $group['held']) {
                return self::INCOMPLETE;
            }
        }

        foreach ($groups as $group) {
            if ($group['expired']) {
                return self::NEEDS_REPLACEMENT;
            }
        }

        return self::COMPLIANT;
    }

    /**
     * The status of many employees at once, in two queries rather than
     * two per employee.
     *
     * Used by the Employee PPE list, which renders 24 people a page: the
     * per-employee path would make this an N+1 the moment it shipped.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, string> employee id => status
     */
    public function statusesFor(Collection $employees): array
    {
        if ($employees->isEmpty()) {
            return [];
        }

        $assignments = EmployeePpe::withoutGlobalScopes()
            ->with('ppeType:id,name')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereIn('status', EmployeePpe::IN_SERVICE_STATUSES)
            ->get()
            ->groupBy('employee_id');

        $statuses = [];

        foreach ($employees as $employee) {
            $statuses[(int) $employee->id] = $this->evaluate(
                $employee,
                $assignments->get($employee->id) ?? collect()
            )['status'];
        }

        return $statuses;
    }

    /**
     * The ids of employees in `$scope` whose status is one of `$statuses`.
     *
     * Exists because compliance is computed in PHP, not SQL: it reads a
     * type's role (which may be inherited from a template) and an item's
     * expiry against today. Filtering a PAGINATED list by a PHP predicate
     * would filter the current page only and report a page count that is
     * simply wrong, so the filter has to resolve its ids BEFORE pagination.
     *
     * Two queries over the scoped set, no matter how many statuses are
     * asked for. The scope is already narrowed by company, department and
     * search before it gets here, which is what keeps this bounded.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $scope  the employee query, unpaginated
     * @param  list<string>  $statuses
     * @return list<int>
     */
    public function employeeIdsWithStatus($scope, array $statuses): array
    {
        $employees = (clone $scope)->get(['employees.id', 'employees.company_id']);

        if ($employees->isEmpty()) {
            return [];
        }

        $wanted = array_flip($statuses);

        return array_values(array_keys(array_filter(
            $this->statusesFor($employees),
            fn (string $status) => isset($wanted[$status])
        )));
    }

    /**
     * Whether this company's catalogue can answer the question at all.
     *
     * A company with no type carrying a given role can NEVER have a
     * compliant employee, and that is a configuration problem, not a
     * workforce problem. The screens say so rather than painting every
     * person red -- the "dead UI" rule in docs/CONVENTIONS.md, applied to
     * a status instead of a button.
     *
     * @return list<string> the required roles no active type covers
     */
    public function unconfiguredRoles(?int $companyId): array
    {
        $covered = [];

        $types = PpeType::withoutGlobalScopes()
            ->effectiveForCompany((int) $companyId)
            ->where('is_active', true)
            ->get(['id', 'company_id', 'source_default_id', 'compliance_role']);

        $byId = PpeType::withoutGlobalScopes()
            ->whereIn('id', $types->pluck('source_default_id')->filter()->all())
            ->get(['id', 'compliance_role'])
            ->keyBy('id');

        foreach ($types as $type) {
            $role = $this->resolveRole($type->compliance_role, $type->source_default_id, $byId);

            if ($role !== null) {
                $covered[$role] = true;
            }
        }

        return array_values(array_filter(
            PpeType::COMPLIANCE_ROLES,
            fn (string $role) => ! isset($covered[$role])
        ));
    }
}
