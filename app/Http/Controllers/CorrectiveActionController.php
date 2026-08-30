<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CorrectiveAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream B15 (CAPA). Standalone, cross-source view over
 * the SAME `CorrectiveAction` rows created from Safety Observation
 * (B1)/HSE Inspection (B2)/Incident (B14) -- not a second CAPA system,
 * the whole point of the entity being polymorphic from the start.
 */
class CorrectiveActionController extends Controller
{
    /**
     * v2.5.0 (Field HSE Experience pass, Part 10 -- CAPA). Added a real
     * `overdue` quick-filter and a tenant-scoped summary count (Open/
     * Overdue/In Progress/Closed), per the explicit product requirement
     * that CAPA should read as an ACTION MANAGEMENT tool ("make overdue
     * actions obvious"), not a plain database table. `is_overdue` itself
     * stays a computed accessor on the model (never stored -- see its
     * own doc comment) so the raw-SQL equivalent below (due_date in the
     * past AND not yet completed/verified/cancelled) is kept in sync
     * with `CorrectiveAction::getIsOverdueAttribute()`'s exact logic by
     * hand -- if that accessor's rule ever changes, this query needs the
     * same change.
     */
    public function index(Request $request): Response
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $openStatuses = [CorrectiveAction::STATUS_OPEN, CorrectiveAction::STATUS_IN_PROGRESS];

        $overdueClause = fn ($q) => $q
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->toDateString())
            ->whereIn('status', $openStatuses);

        $baseQuery = fn () => CorrectiveAction::query()->whereIn('company_id', $tenantCompanyIds);

        $actions = CorrectiveAction::query()
            ->whereIn('company_id', $tenantCompanyIds)
            ->with('assignee:id,name', 'source')
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('priority'), fn ($q, $v) => $q->where('priority', $v))
            ->when($request->boolean('overdue'), $overdueClause)
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (CorrectiveAction $a) => [
                'id' => $a->id,
                'action' => $a->action,
                'status' => $a->status,
                'priority' => $a->priority,
                'due_date' => $a->due_date,
                'is_overdue' => $a->is_overdue,
                'assignee' => $a->assignee,
                'source_type' => class_basename($a->source_type),
                'source_label' => $this->sourceLabel($a),
                'source_route' => $this->sourceRoute($a),
            ]);

        return Inertia::render('CorrectiveActions/Index', [
            'actions' => $actions,
            'filters' => $request->only('status', 'priority', 'overdue'),
            // $baseQuery() returns a fresh Builder on every call -- no
            // clone needed, each of these four counts runs its own
            // independent query.
            'summary' => [
                'open' => $baseQuery()->where('status', CorrectiveAction::STATUS_OPEN)->count(),
                'in_progress' => $baseQuery()->where('status', CorrectiveAction::STATUS_IN_PROGRESS)->count(),
                'overdue' => $overdueClause($baseQuery())->count(),
                'closed' => $baseQuery()->whereIn('status', [CorrectiveAction::STATUS_COMPLETED, CorrectiveAction::STATUS_VERIFIED])->count(),
            ],
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function updateStatus(Request $request, CorrectiveAction $correctiveAction): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($correctiveAction->company_id), 404);

        $data = $request->validate([
            'status' => ['required', Rule::in([
                CorrectiveAction::STATUS_IN_PROGRESS, CorrectiveAction::STATUS_COMPLETED,
                CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED,
            ])],
        ]);

        $update = ['status' => $data['status']];
        if ($data['status'] === CorrectiveAction::STATUS_VERIFIED) {
            $update['verified_by'] = $request->user()->id;
            $update['verified_at'] = now();
        }
        if ($data['status'] === CorrectiveAction::STATUS_COMPLETED) {
            $update['closed_at'] = now();
        }

        $correctiveAction->update($update);

        return back()->with('success', 'Corrective action updated.');
    }

    private function sourceLabel(CorrectiveAction $a): ?string
    {
        if (! $a->source) {
            return null;
        }

        return match (class_basename($a->source_type)) {
            'SafetyObservation' => $a->source->observation_number,
            'HseInspection' => $a->source->inspection_number,
            'Incident' => $a->source->incident_number,
            default => (string) $a->source->getKey(),
        };
    }

    private function sourceRoute(CorrectiveAction $a): ?string
    {
        if (! $a->source) {
            return null;
        }

        return match (class_basename($a->source_type)) {
            'SafetyObservation' => route('safety-observations.show', $a->source_id),
            'HseInspection' => route('hse-inspections.show', $a->source_id),
            'Incident' => route('incidents.show', $a->source_id),
            default => null,
        };
    }
}
