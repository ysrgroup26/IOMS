<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\EmployeeRoster;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream A3. Cross-employee Roster Overview -- the real
 * "who is on/off duty, on what shift, at what site, right now" answer,
 * scoped server-side to the current tenant's own companies only (same
 * pattern as CompetencyController::expiringSoon()).
 */
class RosterController extends Controller
{
    public function overview(Request $request): Response
    {
        $companyIds = Company::query()->pluck('id')->all();
        $requestedCompanyId = $request->input('company_id') ? (int) $request->input('company_id') : null;
        $effectiveCompanyIds = ($requestedCompanyId && in_array($requestedCompanyId, $companyIds, true))
            ? [$requestedCompanyId]
            : $companyIds;

        $today = now();

        $rosters = EmployeeRoster::query()
            ->current()
            ->whereHas('employee', fn ($q) => $q->whereIn('company_id', $effectiveCompanyIds))
            ->with(
                'employee:id,employee_id,full_name,company_id,department_id',
                'employee.company:id,name',
                'employee.department:id,name',
                'shift:id,name,code,start_time,end_time',
                'rosterPattern:id,name,days_on,days_off',
                'project:id,name'
            )
            ->get()
            ->map(function (EmployeeRoster $roster) use ($today) {
                return [
                    'id' => $roster->id,
                    'employee_id' => $roster->employee->id,
                    'employee_name' => $roster->employee->full_name,
                    'employee_code' => $roster->employee->employee_id,
                    'company' => $roster->employee->company?->name,
                    'department' => $roster->employee->department?->name,
                    'shift' => $roster->shift ? ['name' => $roster->shift->name, 'code' => $roster->shift->code] : null,
                    'roster_pattern' => $roster->rosterPattern?->name,
                    'site' => $roster->project?->name ?? $roster->site_name,
                    'start_date' => $roster->start_date->format('d M Y'),
                    'end_date' => $roster->end_date?->format('d M Y'),
                    'duty_today' => $roster->dutyTypeOn($today),
                ];
            });

        return Inertia::render('Rosters/Overview', [
            'rosters' => $rosters,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'filters' => ['company_id' => $requestedCompanyId],
        ]);
    }
}
