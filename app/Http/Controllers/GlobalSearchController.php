<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\GoodsReceipt;
use App\Models\Incident;
use App\Models\LeaveRequest;
use App\Models\MaterialRequest;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Global search (v1.6.3; generalized in Milestone 3, Task #52). Searches
 * real, existing data only -- every category below is a module that
 * genuinely exists and has a real `show`/detail route to link to.
 * Deliberately still does NOT search Asset/Document/PTW/Inspection, since
 * those modules don't exist in this application yet (a search box that
 * "finds" non-existent records would be fake functionality).
 */
class GlobalSearchController extends Controller
{
    public function search(Request $request)
    {
        $query = trim((string) $request->input('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json($this->emptyResults());
        }

        $employees = Employee::query()
            ->active()
            ->search($query)
            ->with('department:id,name')
            ->limit(5)
            ->get(['id', 'employee_id', 'full_name', 'department_id'])
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'title' => $e->full_name,
                'subtitle' => $e->department?->name,
                'url' => route('employees.show', $e->id),
            ]);

        $projects = Project::query()
            ->where('name', 'like', "%{$query}%")
            ->limit(5)
            ->get(['id', 'name', 'status'])
            ->map(fn (Project $p) => [
                'id' => $p->id,
                'title' => $p->name,
                'subtitle' => ucfirst($p->status),
                'url' => route('projects.show', $p->id),
            ]);

        $incidents = Incident::query()
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
            ->where(fn ($q) => $q->where('request_number', 'like', "%{$query}%")->orWhere('notes', 'like', "%{$query}%"))
            ->limit(5)
            ->get(['id', 'request_number', 'status'])
            ->map(fn (MaterialRequest $m) => [
                'id' => $m->id,
                'title' => $m->request_number,
                'subtitle' => ucfirst($m->status),
                'url' => route('material-requests.show', $m->id),
            ]);

        $leaveRequests = LeaveRequest::query()
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

        $milestones = Milestone::query()
            ->where(fn ($q) => $q->where('milestone_number', 'like', "%{$query}%")->orWhere('title', 'like', "%{$query}%"))
            ->limit(5)
            ->get(['id', 'milestone_number', 'title', 'status'])
            ->map(fn (Milestone $m) => [
                'id' => $m->id,
                'title' => ($m->milestone_number ? $m->milestone_number.' -- ' : '').$m->title,
                'subtitle' => ucfirst(str_replace('_', ' ', $m->status)),
                'url' => route('milestones.index', ['project_id' => $m->project_id]),
            ]);

        $goodsReceipts = GoodsReceipt::query()
            ->where('receipt_number', 'like', "%{$query}%")
            ->limit(5)
            ->get(['id', 'receipt_number'])
            ->map(fn (GoodsReceipt $g) => [
                'id' => $g->id,
                'title' => $g->receipt_number,
                'subtitle' => null,
                'url' => route('goods-receipts.show', $g->id),
            ]);

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

        return response()->json([
            'employees' => $employees,
            'projects' => $projects,
            'incidents' => $incidents,
            'material_requests' => $materialRequests,
            'leave_requests' => $leaveRequests,
            'milestones' => $milestones,
            'goods_receipts' => $goodsReceipts,
            'companies' => $companies,
        ]);
    }

    private function emptyResults(): array
    {
        return [
            'employees' => [], 'projects' => [], 'incidents' => [], 'material_requests' => [],
            'leave_requests' => [], 'milestones' => [], 'goods_receipts' => [], 'companies' => [],
        ];
    }
}
