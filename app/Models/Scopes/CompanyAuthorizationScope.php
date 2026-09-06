<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * v2.53.0 -- PER-COMPANY AUTHORIZATION INSIDE A TENANT.
 *
 * Enterprise sells multi-company: one customer running GAJ and MTC in the
 * same workspace. TenantScope narrows every Company query to the current
 * tenant; nothing narrowed it further, so a yard manager at GAJ could
 * reach MTC's employees, purchase orders and payroll data. Correct for a
 * single-company customer, wrong for a multi-company one.
 *
 * This scope layers on top of TenantScope and only ever REMOVES rows.
 * Because practically every downstream query in this codebase resolves
 * its own scope through `Company::query()->pluck('id')` (see
 * ARCHITECTURE's multi-tenant section), narrowing Company here propagates
 * to employees, permits, purchase orders and the rest without touching
 * any of them — the same transitive-isolation property TenantScope
 * already relies on.
 *
 * THE DEFAULT IS "NO GRANTS = ALL COMPANIES IN THE TENANT", and that is a
 * deliberate, documented choice rather than an oversight:
 *
 *  - Every existing user has no grants, so upgrading changes nothing. A
 *    migration that silently locked customers out of their own data
 *    would be a far worse failure than an opt-in control.
 *  - Authorization becomes real the moment an administrator grants a user
 *    their first company. From then on that user sees only what they were
 *    granted.
 *
 * It is skipped for a Platform Admin (who has no tenant and reaches the
 * platform surface, not tenant data) and whenever there is no
 * authenticated user at all.
 *
 * THE ABSENCE OF A USER is what covers console context -- queue workers,
 * scheduled reports and migrations resolve their tenant explicitly and
 * have nobody to authorize against. An earlier version guarded on
 * `runningInConsole()` instead, which was too broad in exactly the way
 * that matters: PHPUnit also runs in console, so the scope silently did
 * nothing under test and the feature would have shipped unverified.
 * Guarding on the user is both narrower and the real condition.
 */
class CompanyAuthorizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user || $user->isPlatformAdmin()) {
            return;
        }

        $companyIds = $user->authorizedCompanyIds();

        // Null means "not restricted" -- no grants recorded for this user.
        if ($companyIds === null) {
            return;
        }

        $builder->whereIn($model->getTable().'.id', $companyIds);
    }
}
