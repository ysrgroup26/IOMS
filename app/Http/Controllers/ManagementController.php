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
     * The one place both gates are applied, so no action of this
     * controller can be added later without them.
     */
    private function authorizeManagement(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && $user->canViewManagement(),
            403,
            'Halaman Management hanya untuk manajemen dan administrator perusahaan.'
        );

        abort_unless(
            $this->entitlements->tenantCanUseWorkspace($user->tenant, 'management'),
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
