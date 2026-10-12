<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\IncidentInvestigation;
use App\Models\User;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.112.0 -- HSE: the accident investigation register.
 *
 * A SEPARATE record from the incident it investigates (ADR 036), with
 * its own number, its own workflow and its own closure. This register
 * reports the investigation's own fields and carries the incident number
 * only as the link back, never the incident's severity or category --
 * those belong to the initial report and are reported there.
 *
 * `open_actions` is counted from real CorrectiveAction rows, the same
 * rule the HSE inspection register follows: a cached count is a count
 * that can be wrong, and a closed-looking investigation with open
 * actions is exactly the thing an auditor is looking for.
 */
class IncidentInvestigationRegisterDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'incident-investigation-register';
    }

    public function title(): string
    {
        return 'Accident Investigation Register';
    }

    public function description(): string
    {
        return 'Rekapitulasi investigasi insiden beserta penyebab dasar, status dan tindakan perbaikan yang masih terbuka.';
    }

    public function workspace(): string
    {
        return 'hse';
    }

    public function formats(): array
    {
        return DocumentRenderer::FORMATS;
    }

    public function columns(): array
    {
        return [
            ['key' => 'investigation_number', 'label' => 'Investigation No.', 'type' => 'text', 'width' => 22],
            ['key' => 'incident_number', 'label' => 'Incident No.', 'type' => 'text', 'width' => 20],
            ['key' => 'started_at', 'label' => 'Started', 'type' => 'date', 'width' => 14],
            ['key' => 'target_completion_date', 'label' => 'Target', 'type' => 'date', 'width' => 14],
            ['key' => 'investigator', 'label' => 'Investigator', 'type' => 'text', 'width' => 20],
            ['key' => 'method', 'label' => 'Method', 'type' => 'text', 'width' => 16],
            ['key' => 'root_cause', 'label' => 'Root Cause', 'type' => 'text', 'width' => 34],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 14],
            ['key' => 'closed_at', 'label' => 'Closed', 'type' => 'date', 'width' => 14],
            ['key' => 'open_actions', 'label' => 'Open Actions', 'type' => 'number', 'width' => 14],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = IncidentInvestigation::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('incident:id,incident_number', 'investigator:id,name')
            ->withCount(['correctiveActions as open_actions_count' => fn ($q) => $q->whereIn('status', ['open', 'in_progress'])])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('started_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('started_at', '<=', $v))
            ->orderByDesc('started_at');

        foreach ($query->cursor() as $investigation) {
            yield [
                'investigation_number' => $investigation->investigation_number,
                'incident_number' => $investigation->incident?->incident_number,
                'started_at' => $investigation->started_at,
                'target_completion_date' => $investigation->target_completion_date,
                'investigator' => $investigation->investigator?->name,
                'method' => DocumentLabel::verdict($investigation->method),
                'root_cause' => $investigation->root_cause,
                'status' => DocumentLabel::humanize($investigation->status),
                'closed_at' => $investigation->closed_at,
                'open_actions' => $investigation->open_actions_count,
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Status' => DocumentLabel::humanize($filters['status'] ?? null),
            'Periode' => DocumentLabel::period($filters),
        ];
    }

    public function authorize(User $user): bool
    {
        return true;
    }
}
