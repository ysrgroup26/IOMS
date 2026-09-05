<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Models\User;

/**
 * v2.39.0 -- operational readiness.
 *
 * THE PROBLEM THIS SOLVES (observed in a browser, on a genuinely empty
 * tenant, not inferred from code): a brand-new customer signs in and the
 * dashboard tells them
 *
 *     "Today's Alerts -- 0 Lost Time Incidents, 0 PPE Alerts
 *      ✅ Great job! No critical issues detected today."
 *
 * IOMS is congratulating a company on safety performance it has no data
 * for. Every operational KPI reads 0 because the database is empty, and
 * an empty system is rendered identically to a healthy one. For an HSE
 * platform that is not merely an awkward empty state -- it is a claim
 * about safety that nothing supports, shown to the exact person (an HSE
 * Manager) whose job is to trust it.
 *
 * THE DISTINCTION THIS SERVICE MAKES: a zero is only good news once
 * something could have produced a non-zero.
 *
 *     0 open incidents across 500 employees  -> genuinely healthy
 *     0 open incidents across 0 employees    -> means nothing at all
 *
 * `Employee` is the gate. It is the foundational workforce record that
 * PPE, man-hours, KPI records, PTW workforce, leave, competency and
 * rosters all hang off -- with no employees, essentially every
 * operational metric in IOMS is structurally incapable of being non-zero.
 * That makes "has at least one employee" an honest, derivable signal
 * rather than an invented onboarding flag.
 *
 * DELIBERATELY NOT A NEW SUBSYSTEM: no new table, no persisted
 * onboarding state, no wizard, no dismissible tour. Everything here is
 * derived live from records that already exist, so it self-corrects --
 * a tenant that deletes all its employees honestly returns to "not
 * operational", and nothing can drift out of sync with reality.
 *
 * Tenant-scoped through `Company::query()`, which passes through
 * TenantScope -- the same authority every guarded query in this codebase
 * uses.
 */
class TenantReadinessService
{
    /** Memoised per request -- `isOperational()` is read on every authenticated page load via Inertia shared props. */
    private ?bool $operational = null;

    /**
     * The cheap global signal: ONE indexed EXISTS query, memoised.
     *
     * Deliberately separated from `snapshot()` so it can be shared on
     * EVERY authenticated page (see HandleInertiaRequests) without paying
     * for the full five-query setup breakdown, which only the Dashboard's
     * setup UI actually needs. Any surface that must avoid presenting an
     * empty system as a healthy one can read this for free.
     */
    public function isOperational(): bool
    {
        return $this->operational ??= Employee::whereIn('company_id', Company::query()->pluck('id'))->exists();
    }

    /**
     * @return array{is_operational: bool, completed: int, total: int, steps: array<int, array{key: string, label: string, description: string, done: bool, href: string|null}>}
     */
    public function snapshot(): array
    {
        $companyIds = Company::query()->pluck('id');

        $hasCompanies = $companyIds->isNotEmpty();
        $hasDepartments = Department::whereIn('company_id', $companyIds)->exists();
        $hasEmployees = Employee::whereIn('company_id', $companyIds)->exists();
        $hasProjects = Project::whereIn('company_id', $companyIds)->exists();

        // "More than the founding admin account" -- one user always exists
        // (whoever is looking at this), so that alone proves nothing.
        $hasTeam = User::where('tenant_id', app(\App\Support\CurrentTenant::class)->id())->count() > 1;

        $steps = [
            [
                'key' => 'company',
                'label' => 'Company profile',
                'description' => 'Identitas perusahaan yang muncul di dokumen dan laporan.',
                'done' => $hasCompanies,
                'href' => 'settings.index',
            ],
            [
                'key' => 'departments',
                'label' => 'Departments',
                'description' => 'Struktur departemen untuk mengelompokkan pekerja dan data operasional.',
                'done' => $hasDepartments,
                'href' => 'settings.index',
            ],
            [
                'key' => 'employees',
                'label' => 'Workforce',
                'description' => 'Data karyawan — dasar dari PPE, man-hour, KPI, dan PTW.',
                'done' => $hasEmployees,
                'href' => 'employees.index',
            ],
            [
                'key' => 'projects',
                'label' => 'Projects',
                'description' => 'Proyek atau area kerja tempat aktivitas operasional dicatat.',
                'done' => $hasProjects,
                'href' => 'projects.index',
            ],
            [
                'key' => 'team',
                'label' => 'Team access',
                'description' => 'Akun untuk tim HSE, HR, dan operasional Anda.',
                'done' => $hasTeam,
                'href' => 'settings.index',
            ],
        ];

        return [
            // The gate. Deliberately NOT "all steps done" -- a tenant with
            // workforce data is genuinely operational and its zeros are
            // genuinely meaningful, even if they have not added projects
            // or extra users yet. Overstating what counts as "set up"
            // would just replace one dishonest state with another.
            'is_operational' => $this->operational ??= $hasEmployees,
            'completed' => count(array_filter($steps, fn ($s) => $s['done'])),
            'total' => count($steps),
            'steps' => $steps,
        ];
    }
}
