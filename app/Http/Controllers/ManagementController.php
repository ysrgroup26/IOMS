<?php

namespace App\Http\Controllers;

use App\Services\EntitlementService;
use App\Services\ManagementInsightsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.83.0 -- THE MANAGEMENT WORKSPACE.
 *
 * Business-tier, read-only, company-wide. It is NOT a renamed Dashboard
 * and NOT a second Reports page -- see ADR 044 for the three questions
 * IOMS now answers separately, and ManagementInsightsService for the rule
 * that decides which figures belong here.
 *
 * EVERY ACTION IS A READ. There is no store/update/destroy in this class
 * and no route for one, which is why it needs no approval flow, no
 * numbering format and no write-access middleware interaction: a lapsed
 * (read-only) subscription can still open Management, exactly as it can
 * still open the record it is reading.
 *
 * TWO GATES, BOTH SERVER-SIDE, AND THEY ANSWER DIFFERENT QUESTIONS.
 *
 *   ENTITLEMENT  does this ORGANIZATION's plan include Management?
 *                (`management` is a department-tier workspace granted by
 *                config/plans.php to Business and above)
 *
 *   CAPABILITY   is this PERSON management?
 *                (`User::canViewManagement()` -- tenant administrator or
 *                Manager; an HSE supervisor is deliberately not)
 *
 * Both are checked here rather than relying on `EnforceTenantEntitlement`
 * alone. That middleware is real enforcement and does resolve this
 * prefix's owning workspace through config/departments.php, but it is
 * switchable (`saas.enforce_workspace_entitlement`) and a capability
 * check is not its job at all. Navigation hiding is not part of this
 * list: `resources/js/lib/workspaces.js` hides the workspace for an
 * unentitled tenant, and that is a courtesy, never the boundary.
 */
class ManagementController extends Controller
{
    public function __construct(
        private readonly ManagementInsightsService $insights,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * The one place the gate is applied, so no action of this controller can
     * be added later without it.
     *
     * v2.84.0 -- THE 403 ROOT CAUSE, AND WHY THE SECOND GATE IS GONE.
     *
     * v2.83.0 required a workspace-specific ROLE in addition to the plan:
     * tenant administrator or Manager, with HSE deliberately excluded. That
     * reasoning was defensible in isolation and wrong for this product,
     * because it made Management the ONLY workspace in IOMS that asks a
     * question the others do not:
     *
     *   Logistics   plan grant + department assignment
     *   People      plan grant + department assignment
     *   HSE         plan grant + department assignment
     *   Management  plan grant + department assignment + a role allow-list
     *
     * The consequence was a 403 for accounts the customer had paid for --
     * confirmed against real data, where an HRD account on a tenant that
     * grants Management was refused, and an HSE-assigned account was refused
     * twice over (the role gate here, and `RestrictDepartmentAccess` denying
     * the `management` prefix before the request ever arrived).
     *
     * `userCanUseWorkspace()` is now the same question every workspace asks:
     * the ORGANIZATION's plan must include it, and a Department User stays
     * inside their own department. Nothing was weakened -- the plan boundary
     * and the department boundary are both still enforced server-side, and
     * this workspace has no write actions to authorize beyond them.
     */
    private function authorizeManagement(Request $request): void
    {
        abort_unless(
            $this->entitlements->userCanUseWorkspace($request->user(), 'management'),
            403,
            'Workspace Management tersedia pada paket Business.'
        );
    }

    /** The period every page shares: a year, and optionally one month within it. */
    private function period(Request $request): array
    {
        $year = (int) $request->integer('year', (int) now()->year);
        $month = $request->filled('month') ? (int) $request->integer('month') : null;

        // Clamped rather than validated-and-rejected: a nonsense query
        // string on a read-only report should show the current period, not
        // a validation error page.
        if ($year < 2000 || $year > (int) now()->year + 1) {
            $year = (int) now()->year;
        }

        if ($month !== null && ($month < 1 || $month > 12)) {
            $month = null;
        }

        return [$year, $month];
    }

    /**
     * EXECUTIVE OVERVIEW -- what → status → priority → action, in that
     * order down the page.
     */
    public function overview(Request $request): Response
    {
        $this->authorizeManagement($request);
        [$year, $month] = $this->period($request);

        return Inertia::render('Management/Overview', [
            'summary' => $this->insights->executiveSummary(),
            'departments' => $this->insights->departmentComparison($year, $month),
            'hse' => $this->insights->hsePerformance(6),
            'actions' => $this->insights->outstandingActions(6),
            'period' => ['year' => $year, 'month' => $month],
        ]);
    }

    public function kpi(Request $request): Response
    {
        $this->authorizeManagement($request);
        [$year, $month] = $this->period($request);

        return Inertia::render('Management/Kpi', [
            'kpi' => $this->insights->companyKpi($year, $month),
            'departments' => $this->insights->departmentComparison($year, $month),
            'period' => ['year' => $year, 'month' => $month],
            'years' => range((int) now()->year, (int) now()->year - 4),
        ]);
    }

    public function hse(Request $request): Response
    {
        $this->authorizeManagement($request);

        return Inertia::render('Management/Hse', [
            'hse' => $this->insights->hsePerformance(12),
            'compliance' => $this->insights->compliance(),
        ]);
    }

    public function workforce(Request $request): Response
    {
        $this->authorizeManagement($request);

        return Inertia::render('Management/Workforce', [
            'workforce' => $this->insights->workforce(),
        ]);
    }

    public function logistics(Request $request): Response
    {
        $this->authorizeManagement($request);

        return Inertia::render('Management/Logistics', [
            'logistics' => $this->insights->logistics(),
        ]);
    }

    public function actions(Request $request): Response
    {
        $this->authorizeManagement($request);

        return Inertia::render('Management/Actions', [
            'actions' => $this->insights->outstandingActions(25),
        ]);
    }
}
