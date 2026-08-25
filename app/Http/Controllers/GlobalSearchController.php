<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\GoodsReceipt;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\MaterialRequest;
use App\Models\Milestone;
use App\Models\PermitToWork;
use App\Models\Project;
use App\Models\Vendor;
use App\Services\DashboardStatsService;
use Illuminate\Http\Request;

/**
 * Global search (v1.6.3; generalized in Milestone 3, Task #52; tenant-
 * scoped and extended in v2.2.0, IOMS OS Ecosystem pass Part 7).
 *
 * SECURITY FIX (v2.2.0): every query in this controller previously ran
 * with NO tenant scoping at all -- `Employee`/`Project`/`Incident`/etc.
 * have no automatic global scope (only `Company` does, via `TenantScope`
 * -- see `App\Models\Scopes\TenantScope`'s own doc comment), so a plain
 * `Employee::query()->search($q)` with no `company_id` filter could
 * return another tenant's employees to any authenticated user in a
 * multi-tenant install. Confirmed by reading every query in this file
 * before this pass -- none of them referenced `company_id` at all. Fixed
 * by resolving the current tenant's visible company IDs exactly the way
 * every dashboard controller already does
 * (`DashboardStatsService::resolveCompanyIds()`, which reads
 * `Company::query()->pluck('id')` -- tenant-scoped via `TenantScope`) and
 * applying `whereIn('company_id', $companyIds)` (or the equivalent
 * relation-based scope where a model has no direct `company_id` column)
 * to every category below.
 *
 * v2.2.0 also added 5 new categories per the explicit request list
 * (PPE, CAPA, PTW, Asset, Vendor) -- still deliberately NOT searching
 * Warehouse Item / Controlled Document / Safety Observation in this pass
 * (kept the same "only real, already-linkable modules" discipline this
 * controller's own original doc comment established, given the time
 * budget for this pass); add a category here the same way whenever one
 * of those becomes a priority, following the exact tenant-scoping
 * pattern every category below already uses.
 */
class GlobalSearchController extends Controller
{
    public function __construct(private readonly DashboardStatsService $stats) {}

    public function search(Request $request)
    {
        $query = trim((string) $request->input('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json($this->emptyResults());
        }

        $user = $request->user();
        $companyIds = $this->stats->resolveCompanyIds(null);

        $employees = Employee::query()
            ->whereIn('employees.company_id', $companyIds)
            ->active()
            ->search($query)
            ->with('department:id,name')
            ->limit(5)
            ->get(['employees.id', 'employee_id', 'full_name', 'department_id'])
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'title' => $e->full_name,
                'subtitle' => $e->department?->name,
                'url' => route('employees.show', $e->id),
            ]);

        $projects = Project::query()
            ->whereIn('company_id', $companyIds)
            ->where('name', 'like', "%{$query}%")
            ->limit(5)
            ->get(['id', 'name', 'status'])
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'title' => $p->name,
                'subtitle' => ucfirst($p->status),
                'url' => route('projects.show', $p->id),
            ]);

        // Incident.company_id is nullable (older migration convention --
        // see IncidentController's own doc comment); a null-company
        // incident is included here too, same established convention
        // DashboardController's own cross-department counters already
        // follow for this exact model.
        $incidents = Incident::query()
            ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
            ->where(fn ($q) => $q->where('incident_number', 'like', "%{$query}%")->orWhere('title', 'like', "%{$query}%"))
            ->limit(5)
            ->get(['id', 'incident_number', 'title', 'status'])
            ->map(fn (Incident $i) => [
                'id' => $i->id,
                'title' => $i->incident_number.' -- '.$i->title,
                'subtitle' => ucfirst(str_replace('_', ' ', $i->status)),
                'url' => route('incidents.show', $i->id),
            ]);

        $materialRequests = MaterialRequest::query()
            ->whereIn('company_id', $companyIds)
            ->where(fn ($q) => $q->where('request_number', 'like', "%{$query}%")->orWhere('notes', 'like', "%{$query}%"))
            ->limit(5)
            ->get(['id', 'request_number', 'status'])
            ->map(fn (MaterialRequest $m) => [
                'id' => $m->id,
                'title' => $m->request_number,
                'subtitle' => ucfirst($m->status),
                'url' => route('material-requests.show', $m->id),
            ]);

        // LeaveRequest.company_id is nullable, same reasoning as Incident above.
        $leaveRequests = LeaveRequest::query()
            ->where(fn ($q) => $q->whereIn('company_id', $companyIds)->orWhereNull('company_id'))
            ->where('leave_number', 'like', "%{$query}%")
            ->with('employee:id,full_name')
            ->limit(5)
            ->get(['id', 'leave_number', 'employee_id', 'status'])
            ->map(fn (LeaveRequest $l) => [
                'id' => $l->id,
                'title' => $l->leave_number.' -- '.($l->employee?->full_name ?? ''),
                'subtitle' => ucfirst($l->status),
                'url' => route('leave-requests.show', $l->id),
            ]);

        // Milestone has no own company_id -- scoped via its owning Project.
        $milestones = Milestone::query()
            ->whereHas('project', fn ($q) => $q->whereIn('company_id', $companyIds))
            ->where(fn ($q) => $q->where('milestone_number', 'like', "%{$query}%")->orWhere('title', 'like', "%{$query}%"))
            ->limit(5)
            ->get(['id', 'milestone_number', 'title', 'status', 'project_id'])
            ->map(fn (Milestone $m) => [
                'id' => $m->id,
                'title' => ($m->milestone_number ? $m->milestone_number.' -- ' : '').$m->title,
                'subtitle' => ucfirst(str_replace('_', ' ', $m->status)),
                'url' => route('milestones.index', ['project_id' => $m->project_id]),
            ]);

        // GoodsReceipt has no own company_id -- scoped via its (nullable)
        // MaterialRequest or Project relation, whichever is set.
        $goodsReceipts = GoodsReceipt::query()
            ->where(fn ($q) => $q
                ->whereHas('materialRequest', fn ($mr) => $mr->whereIn('company_id', $companyIds))
                ->orWhereHas('project', fn ($p) => $p->whereIn('company_id', $companyIds)))
            ->where('receipt_number', 'like', "%{$query}%")
            ->limit(5)
            ->get(['id', 'receipt_number'])
            ->map(fn (GoodsReceipt $g) => [
                'id' => $g->id,
                'title' => $g->receipt_number,
                'subtitle' => null,
                'url' => route('goods-receipts.show', $g->id),
            ]);

        // Company itself is already tenant-scoped automatically via
        // TenantScope -- no manual whereIn needed, unlike every model above.
        $companies = Company::query()
            ->where('name', 'like', "%{$query}%")
            ->limit(5)
            ->get(['id', 'name', 'code'])
            ->map(fn (Company $c) => [
                'id' => $c->id,
                'title' => $c->name,
                'subtitle' => $c->code,
                'url' => route('settings.index', ['tab' => 'companies']),
            ]);

        // -- v2.2.0 additions -- RBAC-gated in addition to tenant-scoped:
        // each category only runs its query at all if the searching user
        // actually has the same capability its own module page already
        // requires, so search can never surface a record type the user
        // couldn't otherwise reach (Part 15's explicit "must not bypass
        // authorization" requirement).
        $ppe = ($user && $user->canManagePpeDistribution())
            ? EmployeePpe::query()
                // Tenant scope is an unconditional AND against the search
                // term's OR -- deliberately NOT folded into either branch
                // of the name-match below, otherwise the ppeType-name
                // branch would run with no company scoping at all.
                ->whereHas('employee', fn ($q) => $q->whereIn('company_id', $companyIds))
                ->where(fn ($q) => $q
                    ->whereHas('ppeType', fn ($t) => $t->where('name', 'like', "%{$query}%"))
                    ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$query}%")))
                ->with(['employee:id,full_name', 'ppeType:id,name'])
                ->limit(5)
                ->get(['id', 'employee_id', 'ppe_type_id', 'status'])
                ->map(fn (EmployeePpe $p) => [
                    'id' => $p->id,
                    'title' => ($p->ppeType?->name ?? 'PPE').' -- '.($p->employee?->full_name ?? ''),
                    'subtitle' => ucfirst(str_replace('_', ' ', $p->status)),
                    'url' => route('ppe.employees', ['search' => $p->employee?->full_name]),
                ])
            : collect();

        $capas = ($user && $user->canManageHse())
            ? CorrectiveAction::query()
                ->whereIn('company_id', $companyIds)
                ->where('action', 'like', "%{$query}%")
                ->limit(5)
                ->get(['id', 'action', 'status'])
                ->map(fn (CorrectiveAction $c) => [
                    'id' => $c->id,
                    'title' => \Illuminate\Support\Str::limit($c->action, 60),
                    'subtitle' => ucfirst(str_replace('_', ' ', $c->status)),
                    'url' => route('corrective-actions.index'),
                ])
            : collect();

        $ptws = ($user && $user->canManageHse())
            ? PermitToWork::query()
                ->whereIn('company_id', $companyIds)
                ->where(fn ($q) => $q->where('ptw_number', 'like', "%{$query}%")->orWhere('work_description', 'like', "%{$query}%"))
                ->limit(5)
                ->get(['id', 'ptw_number', 'status'])
                ->map(fn (PermitToWork $p) => [
                    'id' => $p->id,
                    'title' => $p->ptw_number,
                    'subtitle' => ucfirst(str_replace('_', ' ', $p->status)),
                    'url' => route('permits-to-work.show', $p->id),
                ])
            : collect();

        $assets = ($user && $user->canManageAssets())
            ? Asset::query()
                ->whereIn('company_id', $companyIds)
                ->where(fn ($q) => $q->where('name', 'like', "%{$query}%")->orWhere('asset_code', 'like', "%{$query}%"))
                ->limit(5)
                ->get(['id', 'name', 'asset_code', 'status'])
                ->map(fn (Asset $a) => [
                    'id' => $a->id,
                    'title' => $a->asset_code.' -- '.$a->name,
                    'subtitle' => ucfirst(str_replace('_', ' ', $a->status)),
                    'url' => route('assets.show', $a->id),
                ])
            : collect();

        $vendors = ($user && $user->canManageProcurement())
            ? Vendor::query()
                ->whereIn('company_id', $companyIds)
                ->where(fn ($q) => $q->where('name', 'like', "%{$query}%")->orWhere('vendor_code', 'like', "%{$query}%"))
                ->limit(5)
                ->get(['id', 'name', 'vendor_code'])
                ->map(fn (Vendor $v) => [
                    'id' => $v->id,
                    'title' => $v->name,
                    'subtitle' => $v->vendor_code,
                    'url' => route('vendors.show', $v->id),
                ])
            : collect();

        return response()->json([
            'employees' => $employees,
            'projects' => $projects,
            'incidents' => $incidents,
            'material_requests' => $materialRequests,
            'leave_requests' => $leaveRequests,
            'milestones' => $milestones,
            'goods_receipts' => $goodsReceipts,
            'companies' => $companies,
            'ppe' => $ppe,
            'capas' => $capas,
            'ptws' => $ptws,
            'assets' => $assets,
            'vendors' => $vendors,
        ]);
    }

    private function emptyResults(): array
    {
        return [
            'employees' => [], 'projects' => [], 'incidents' => [], 'material_requests' => [],
            'leave_requests' => [], 'milestones' => [], 'goods_receipts' => [], 'companies' => [],
            'ppe' => [], 'capas' => [], 'ptws' => [], 'assets' => [], 'vendors' => [],
        ];
    }
}
