<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\EmployeePpe;
use App\Models\Incident;
use App\Models\Project;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HSE Department Dashboard (v1.10.0). Distinct from PPE's own dashboard
 * (`ppe.dashboard`, unchanged, still the detailed PPE-specific view) --
 * this is the broader department overview combining PPE + the new
 * Incident Management module. Deliberately does NOT include Safe Man
 * Hours, Open PTW, Inspection Due, Risk Assessment Status, Upcoming
 * Safety Meeting, Training Due, or a Calendar -- none of those have a
 * backing data model yet (no man-hours tracking, no PTW/Inspection/Risk
 * Assessment/Safety Meeting/Training modules exist). "Keep existing
 * useful widgets, improve them instead of replacing them" -- PPE's own
 * dashboard is untouched; this page only adds a new, real overview layer
 * above it.
 */
class HseDashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Hse/Dashboard', [
            'activeProjectsCount' => Project::whereIn('status', ['planned', 'ongoing'])->count(),
            'openIncidentsCount' => Incident::whereIn('status', [Incident::STATUS_REPORTED, Incident::STATUS_INVESTIGATING])->count(),
            'incidentsBySeverity' => Incident::whereIn('status', [Incident::STATUS_REPORTED, Incident::STATUS_INVESTIGATING])
                ->selectRaw('severity, count(*) as total')
                ->groupBy('severity')
                ->pluck('total', 'severity'),
            'ppeAlertCount' => EmployeePpe::query()->effectiveStatus('expiring_soon')->count()
                + EmployeePpe::query()->effectiveStatus('expired')->count(),
            'recentIncidents' => Incident::with('reporter:id,name')
                ->latest('incident_date')
                ->limit(5)
                ->get(['id', 'incident_number', 'title', 'severity', 'status', 'incident_date', 'reported_by']),
            'recentActivity' => ActivityLog::whereIn('subject_type', [Incident::class, EmployeePpe::class])
                ->with('user:id,name')
                ->latest()
                ->limit(6)
                ->get(['id', 'user_id', 'description', 'created_at']),
        ]);
    }
}
