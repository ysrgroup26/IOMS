<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\CorrectiveAction;
use App\Models\EmployeePpe;
use App\Models\PermitToWork;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Stock;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * Work Center (v1.8.0 -- Navigation Architecture Redesign, see
 * docs/ADR/007-workspace-navigation.md). NOT a Department -- a global,
 * cross-cutting aggregation of work assigned to the current user, sourced
 * entirely from engines that already exist (Universal Approval Engine,
 * Universal Task Engine, PPE alerts). No new record types are introduced
 * here; this service only queries and shapes existing data so the topbar
 * badge and the Work Center page share one implementation.
 */
class WorkCenterService
{
    public function __construct(private readonly DashboardStatsService $stats = new DashboardStatsService()) {}

    /**
     * Pending approvals the given user is entitled to decide, per
     * config('workflow.approvers') -- the exact same rule
     * ApprovalController::authorizeDecision() already enforces, so this
     * list never shows an approval the user couldn't actually act on.
     *
     * Company scoping: only applied when the VIEWER has a company_id.
     * Most internal staff (managers, HSE, Super Admin) have a null
     * company_id by design (see User::company()'s own doc comment) --
     * scoping those users would hide every approval from the majority of
     * approvers, not narrow it usefully. A company-scoped user (a future
     * Company Admin from the tenant registration flow) only sees
     * approvals for their own company.
     *
     * SECURITY FIX (v2.2.0, IOMS OS Ecosystem pass Part 15): the above
     * per-user narrowing was the ONLY scoping this method ever applied --
     * there was no tenant boundary at all. Since most internal staff
     * genuinely do have a null `company_id` (by the design this doc
     * comment already explains), the `if ($user->company_id && ...)`
     * check was simply skipped for the common case, and EVERY tenant's
     * pending approvals were returned to any Super Admin/HSE/Manager,
     * regardless of which tenant they actually belonged to -- a real
     * cross-tenant leak, confirmed by reading this method before this
     * pass. Fixed by adding a mandatory tenant-scope check FIRST (via the
     * same `resolveCompanyIds()` pattern this whole service now uses),
     * with the pre-existing per-user company narrowing applied
     * afterward, unchanged, as a second, additional filter.
     */
    public function pendingApprovalsFor(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        $approvers = config('workflow.approvers', []);
        if (! $user->isSuperAdmin() && ! in_array($user->role, $approvers, true)) {
            return collect();
        }

        $companyIds = $this->stats->resolveCompanyIds(null);

        return Approval::query()
            ->where('status', Approval::STATUS_PENDING)
            ->with(['approvable', 'requester:id,name'])
            ->latest()
            ->get()
            ->filter(function (Approval $approval) use ($user, $companyIds) {
                $approvable = $approval->approvable;
                if (! $approvable) {
                    return false;
                }

                // Tenant boundary -- mandatory, checked first.
                if (! empty($approvable->company_id) && ! in_array($approvable->company_id, $companyIds, true)) {
                    return false;
                }

                if ($user->company_id && ! empty($approvable->company_id)) {
                    return $approvable->company_id === $user->company_id;
                }

                return true;
            })
            ->values();
    }

    /** Open tasks assigned to the given user -- reuses Task's own scopes, no new query logic. */
    public function myTasksFor(?User $user): Collection
    {
        if (! $user) {
            return collect();
        }

        return Task::query()
            ->assignedTo($user->id)
            ->openStatus()
            ->with('company:id,name')
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->get();
    }

    /**
     * PPE items expiring soon or already expired -- the exact query
     * HandleInertiaRequests already ran for the old PPE-only bell, moved
     * here so the topbar badge and the Work Center page use one
     * implementation instead of two copies drifting apart.
     *
     * SECURITY FIX (v2.2.0, IOMS OS Ecosystem pass Part 15): this query
     * had NO tenant scoping at all -- `EmployeePpe` has no direct
     * `company_id` column and no automatic tenant scope (only `Company`
     * has `TenantScope`), so the topbar bell badge and Work Center's own
     * PPE alert count were previously summing expiring/expired PPE
     * across EVERY tenant, not just the current one. A real cross-tenant
     * count leak (not full record disclosure, but still real information
     * about another tenant's PPE compliance state), confirmed by reading
     * this method and `scopeEffectiveStatus()` before this pass -- the
     * scope itself only ever filters by status/expiry_date, never
     * company. Fixed by scoping through the `employee` relation to the
     * current tenant's visible companies, same
     * `DashboardStatsService::resolveCompanyIds()` pattern used
     * everywhere else in this class.
     */
    public function ppeAlertCount(): int
    {
        $companyIds = $this->stats->resolveCompanyIds(null);

        return EmployeePpe::query()->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))->effectiveStatus('expiring_soon')->count()
            + EmployeePpe::query()->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))->effectiveStatus('expired')->count();
    }

    /**
     * v2.2.0 (IOMS OS Ecosystem pass, Part 6 -- My Tasks / Action Center).
     * Work Center's `alerts` block previously only ever had one entry
     * (`ppe`). Extended with the same real, already-built counts the Main
     * Dashboard's own cross-department counters use (CAPA/PTW/Stock/
     * Maintenance/Procurement -- see DashboardController's
     * `openCapaCount`/`stockAlertCount`/etc.), reused verbatim rather than
     * re-derived, tenant-scoped via `DashboardStatsService::
     * resolveCompanyIds()` (the same helper every one of those counts
     * already relies on), and gated per-item by the SAME `canManage*()`
     * capability the owning module's own controller requires -- so a user
     * never sees a count for a department they can't act on, matching
     * `quickActionsFor()`'s established gating shape. Every item links
     * directly to the record list where the user would actually act,
     * per the directive's explicit "PPE Expiring -> View PPE" example.
     * No new tables, no fabricated numbers -- every count here already
     * existed and was already correct, only never surfaced together in
     * one place before this pass.
     */
    public function actionAlertsFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $companyIds = $this->stats->resolveCompanyIds(null);
        $alerts = [];

        if ($user->canManageHse()) {
            $capaDue = CorrectiveAction::whereIn('company_id', $companyIds)
                ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED])
                ->whereDate('due_date', '<=', now()->addDays(3)->toDateString())
                ->count();
            if ($capaDue > 0) {
                $alerts[] = ['key' => 'capa_due', 'label' => 'CAPA jatuh tempo', 'count' => $capaDue, 'url' => route('corrective-actions.index')];
            }

            $ptwPending = PermitToWork::whereIn('company_id', $companyIds)
                ->where('status', PermitToWork::STATUS_SUBMITTED)
                ->count();
            if ($ptwPending > 0) {
                $alerts[] = ['key' => 'ptw_pending', 'label' => 'PTW menunggu persetujuan', 'count' => $ptwPending, 'url' => route('permits-to-work.index')];
            }
        }

        if ($user->canManageWarehouse()) {
            $stockAlert = Stock::whereIn('company_id', $companyIds)
                ->whereRaw('stocks.quantity <= (select items.min_stock from items where items.id = stocks.item_id)')
                ->count();
            if ($stockAlert > 0) {
                $alerts[] = ['key' => 'stock_alert', 'label' => 'Stok di bawah batas minimum', 'count' => $stockAlert, 'url' => route('stock.index')];
            }
        }

        if ($user->canManageAssets()) {
            $maintenanceDue = WorkOrder::whereIn('company_id', $companyIds)
                ->whereIn('status', [WorkOrder::STATUS_SCHEDULED, WorkOrder::STATUS_IN_PROGRESS])
                ->where('planned_date', '<=', now()->addDays(7)->toDateString())
                ->count();
            if ($maintenanceDue > 0) {
                $alerts[] = ['key' => 'maintenance_due', 'label' => 'Maintenance akan jatuh tempo', 'count' => $maintenanceDue, 'url' => route('work-orders.index')];
            }
        }

        if ($user->canManageProcurement()) {
            $procurementPending = PurchaseRequisition::whereIn('company_id', $companyIds)
                ->whereIn('status', [PurchaseRequisition::STATUS_SUBMITTED, PurchaseRequisition::STATUS_UNDER_REVIEW])
                ->count()
                + PurchaseOrder::whereIn('company_id', $companyIds)->where('status', PurchaseOrder::STATUS_SUBMITTED)->count();
            if ($procurementPending > 0) {
                $alerts[] = ['key' => 'procurement_pending', 'label' => 'PR/PO menunggu diproses', 'count' => $procurementPending, 'url' => route('purchase-requisitions.index')];
            }
        }

        return $alerts;
    }

    /**
     * v2.2.0 (IOMS OS Ecosystem pass, Part 5 -- Quick Actions). Moved here
     * from WorkCenterController so the Main Dashboard can reuse the exact
     * same module-gated, role-gated, department-tagged action list
     * instead of its own separate hardcoded 4-item card -- one
     * implementation, two surfaces (Work Center page + Main Dashboard),
     * matching this class's own "topbar badge and the Work Center page
     * share one implementation" precedent above. See the method body's
     * own inline comments for the full per-department gating rationale.
     */
    public function quickActionsFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $grantedKeys = $user->tenant ? $user->tenant->modules()->pluck('key')->all() : [];
        $stored = json_decode(
            \App\Models\CompanySetting::where('key', 'enabled_modules')->value('value') ?? json_encode($grantedKeys),
            true
        ) ?? $grantedKeys;
        $enabledModules = collect(array_intersect($stored, $grantedKeys));
        $actions = [];

        // -- HSE --
        if ($enabledModules->contains('ppe')) {
            $actions[] = ['label' => 'PPE Dashboard', 'url' => route('ppe.dashboard'), 'icon' => 'HardHat', 'department' => 'hse'];
        }
        if ($user->canManageIncidents()) {
            $actions[] = ['label' => 'New Incident', 'url' => route('incidents.create'), 'icon' => 'AlertTriangle', 'department' => 'hse'];
        }
        if ($user->canManageSafetyObservations()) {
            $actions[] = ['label' => 'New Safety Observation', 'url' => route('safety-observations.create'), 'icon' => 'Eye', 'department' => 'hse'];
        }
        if ($user->canManageHse()) {
            $actions[] = ['label' => 'New Inspection', 'url' => route('hse-inspections.create'), 'icon' => 'ClipboardCheck', 'department' => 'hse'];
            $actions[] = ['label' => 'CAPA', 'url' => route('corrective-actions.index'), 'icon' => 'ClipboardCheck', 'department' => 'hse'];
            $actions[] = ['label' => 'New PTW', 'url' => route('permits-to-work.create'), 'icon' => 'Flame', 'department' => 'hse'];
            $actions[] = ['label' => 'New JSA', 'url' => route('job-safety-analyses.create'), 'icon' => 'FileWarning', 'department' => 'hse'];
            $actions[] = ['label' => 'New HIRADC', 'url' => route('risk-assessments.create'), 'icon' => 'ShieldAlert', 'department' => 'hse'];
            $actions[] = ['label' => 'Gas Test', 'url' => route('gas-test-records.index'), 'icon' => 'FlaskConical', 'department' => 'hse'];
            // v2.11.0 (Field/Foreman Experience pass, Phase 3H): LOTO had
            // NO entry anywhere in this method -- confirmed via this
            // pass's own audit (LotoRecordController/LotoRecords already
            // has a real, working, standalone Create page since an
            // earlier pass; it simply never got a quick-action wired in
            // like every other HSE module here already has). Same gate
            // as its sibling HSE actions above.
            $actions[] = ['label' => 'New LOTO', 'url' => route('loto-records.create'), 'icon' => 'Lock', 'department' => 'hse'];
            // v2.3.0 (HSE Operations + IOMS OS Ecosystem pass, Part 17):
            // "+ Waste" -- points at New Waste Record (the actual waste
            // material entry point), not Container Inventory, which is a
            // separate stock-management concept reached from the Waste
            // Management dashboard itself.
            $actions[] = ['label' => 'New Waste Record', 'url' => route('waste-records.create'), 'icon' => 'Recycle', 'department' => 'hse'];
        }

        // -- HRD --
        if ($enabledModules->contains('employees') && $user->isAdmin()) {
            $actions[] = ['label' => 'Add Employee', 'url' => route('employees.create'), 'icon' => 'UserPlus', 'department' => 'hr'];
        }
        if ($user->canManageManHour()) {
            $actions[] = ['label' => 'Record Man-Hour', 'url' => route('man-hour.index'), 'icon' => 'Clock', 'department' => 'hr'];
        }
        if ($user->canManageLeaveRequests()) {
            $actions[] = ['label' => 'New Leave Request', 'url' => route('leave-requests.create'), 'icon' => 'CalendarDays', 'department' => 'hr'];
        }
        if ($user->isAdmin()) {
            $actions[] = ['label' => 'Shift & Roster', 'url' => route('shifts.master'), 'icon' => 'Clock', 'department' => 'hr'];
            $actions[] = ['label' => 'Training & Competency', 'url' => route('competency.master'), 'icon' => 'GraduationCap', 'department' => 'hr'];
        }

        // -- Project Management --
        if ($enabledModules->contains('projects') && $user->canManageProjects()) {
            $actions[] = ['label' => 'New Project', 'url' => route('projects.create'), 'icon' => 'FolderKanban', 'department' => 'project-management'];
        }
        if ($enabledModules->contains('daily_reports')) {
            $actions[] = ['label' => 'Daily Report', 'url' => route('daily-reports.create'), 'icon' => 'ClipboardList', 'department' => 'project-management'];
        }
        if ($user->canManageMilestones()) {
            $actions[] = ['label' => 'Milestone', 'url' => route('milestones.index'), 'icon' => 'Flag', 'department' => 'project-management'];
        }

        // -- Logistics / Warehouse --
        if ($enabledModules->contains('material_requests')) {
            $actions[] = ['label' => 'New Material Request', 'url' => route('material-requests.create'), 'icon' => 'PackagePlus', 'department' => 'logistics'];
        }
        if ($user->canManageProcurement()) {
            $actions[] = ['label' => 'Purchase Requisition', 'url' => route('purchase-requisitions.create'), 'icon' => 'FileStack', 'department' => 'procurement'];
            $actions[] = ['label' => 'Purchase Order', 'url' => route('purchase-orders.create'), 'icon' => 'ShoppingCart', 'department' => 'procurement'];
        }
        if ($user->canManageGoodsReceipts()) {
            $actions[] = ['label' => 'Goods Receipt', 'url' => route('goods-receipts.create'), 'icon' => 'PackageCheck', 'department' => 'logistics'];
        }
        if ($user->canManageWarehouse()) {
            $actions[] = ['label' => 'Stock Movement', 'url' => route('stock.transactions.create'), 'icon' => 'ArrowRightLeft', 'department' => 'warehouse'];
        }

        $actions[] = ['label' => 'New Task', 'url' => route('tasks.create'), 'icon' => 'CheckSquare', 'department' => null];

        if ($user->isDepartmentUser()) {
            $actions = array_values(array_filter(
                $actions,
                fn (array $a) => $a['department'] === null || $a['department'] === $user->department_key
            ));
        }

        return $actions;
    }
}
