<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Real, working global search (v1.6.3) -- searches actual existing data
 * (Employees, Projects) by name. Deliberately does NOT search
 * Incidents/Inspections/Permits/Assets, since those modules don't exist
 * in this application yet -- a search box that "finds" non-existent
 * records would be fake functionality, not a shortcut.
 */
class GlobalSearchController extends Controller
{
    public function search(Request $request)
    {
        $query = trim((string) $request->input('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['employees' => [], 'projects' => []]);
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

        return response()->json(['employees' => $employees, 'projects' => $projects]);
    }
}
