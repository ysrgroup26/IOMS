<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\Incident;
use App\Models\User;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.112.0 -- HSE: the incident and accident register.
 *
 * Incident Management is the INITIAL REPORT (ADR 036); HSE Investigation
 * is a separate record with its own register. This document therefore
 * reports what was REPORTED -- date, severity, category, who reported it
 * -- and states whether an investigation exists, without pulling any of
 * the investigation's own fields across that boundary.
 *
 * `investigated` answers one question honestly: is there an
 * investigation record for this incident? It does not claim the
 * investigation is finished, which is the investigation register's job.
 */
class IncidentRegisterDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'incident-register';
    }

    public function title(): string
    {
        return 'Incident & Accident Register';
    }

    public function description(): string
    {
        return 'Rekapitulasi laporan awal insiden dan kecelakaan beserta tingkat keparahan, kategori dan statusnya.';
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
            ['key' => 'incident_number', 'label' => 'Incident No.', 'type' => 'text', 'width' => 20],
            ['key' => 'incident_date', 'label' => 'Date', 'type' => 'date', 'width' => 14],
            ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'width' => 30],
            ['key' => 'category', 'label' => 'Category', 'type' => 'text', 'width' => 16],
            ['key' => 'severity', 'label' => 'Severity', 'type' => 'text', 'width' => 14],
            ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'width' => 20],
            ['key' => 'project', 'label' => 'Project', 'type' => 'text', 'width' => 22],
            ['key' => 'reporter', 'label' => 'Reported By', 'type' => 'text', 'width' => 20],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 14],
            ['key' => 'investigated', 'label' => 'Investigation', 'type' => 'text', 'width' => 14],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = Incident::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('company:id,name', 'project:id,name', 'reporter:id,name')
            ->withCount('investigation')
            ->when($filters['severity'] ?? null, fn ($q, $v) => $q->where('severity', $v))
            ->when($filters['category'] ?? null, fn ($q, $v) => $q->where('category', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('incident_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('incident_date', '<=', $v))
            ->orderByDesc('incident_date');

        foreach ($query->cursor() as $incident) {
            yield [
                'incident_number' => $incident->incident_number,
                'incident_date' => $incident->incident_date,
                'title' => $incident->title,
                'category' => DocumentLabel::humanize($incident->category),
                'severity' => DocumentLabel::verdict($incident->severity),
                'location' => $incident->location,
                'project' => $incident->project?->name,
                'reporter' => $incident->reporter?->name,
                'status' => DocumentLabel::humanize($incident->status),
                // Says only whether a record EXISTS. Whether it is
                // finished is the investigation register's question.
                'investigated' => $incident->investigation_count > 0 ? 'Yes' : 'No',
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Keparahan' => DocumentLabel::verdict($filters['severity'] ?? null),
            'Kategori' => DocumentLabel::humanize($filters['category'] ?? null),
            'Status' => DocumentLabel::humanize($filters['status'] ?? null),
            'Periode' => DocumentLabel::period($filters),
        ];
    }

    public function authorize(User $user): bool
    {
        return true;
    }
}
