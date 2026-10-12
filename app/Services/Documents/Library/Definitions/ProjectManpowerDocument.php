<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.110.0 -- Management: where the workforce is committed.
 *
 * A project portfolio with the headcount actually assigned to each
 * project. Management owns no data of its own (ADR 044), so this
 * definition invents no metric: every figure is counted from the
 * ProjectManpower rows the Projects module already maintains.
 *
 * Headcount is COUNTED, never stored, for the same reason the HSE
 * register counts its open actions: a cached number is a number that can
 * be wrong, and a manpower report that is wrong is worse than none.
 */
class ProjectManpowerDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'project-manpower';
    }

    public function title(): string
    {
        return 'Project Manpower Summary';
    }

    public function description(): string
    {
        return 'Ringkasan proyek beserta jumlah tenaga kerja yang ditugaskan pada masing-masing proyek.';
    }

    public function workspace(): string
    {
        return 'management';
    }

    public function formats(): array
    {
        return DocumentRenderer::FORMATS;
    }

    public function columns(): array
    {
        return [
            ['key' => 'project_code', 'label' => 'Project Code', 'type' => 'text', 'width' => 18],
            ['key' => 'name', 'label' => 'Project', 'type' => 'text', 'width' => 30],
            ['key' => 'client', 'label' => 'Client', 'type' => 'text', 'width' => 24],
            ['key' => 'vessel_name', 'label' => 'Vessel', 'type' => 'text', 'width' => 22],
            ['key' => 'company', 'label' => 'Operating Unit', 'type' => 'text', 'width' => 22],
            ['key' => 'manager', 'label' => 'Project Manager', 'type' => 'text', 'width' => 22],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 14],
            ['key' => 'start_date', 'label' => 'Start Date', 'type' => 'date', 'width' => 14],
            ['key' => 'end_date', 'label' => 'End Date', 'type' => 'date', 'width' => 14],
            ['key' => 'manpower', 'label' => 'Assigned Manpower', 'type' => 'number', 'width' => 18],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = Project::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('company:id,name', 'manager:id,name')
            ->withCount('manpower')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['company_id'] ?? null, fn ($q, $v) => $q->where('company_id', (int) $v))
            ->orderBy('name');

        foreach ($query->cursor() as $project) {
            yield [
                'project_code' => $project->project_code,
                'name' => $project->name,
                'client' => $project->client,
                'vessel_name' => $project->vessel_name,
                'company' => $project->company?->name,
                'manager' => $project->manager?->name,
                'status' => ucwords(str_replace('_', ' ', (string) $project->status)),
                'start_date' => $project->start_date,
                'end_date' => $project->end_date,
                'manpower' => $project->manpower_count,
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Unit' => isset($filters['company_id']) ? Company::find($filters['company_id'])?->name : null,
            'Status' => ($filters['status'] ?? null) ? ucwords(str_replace('_', ' ', $filters['status'])) : null,
        ];
    }

    public function authorize(User $user): bool
    {
        return true;
    }
}
