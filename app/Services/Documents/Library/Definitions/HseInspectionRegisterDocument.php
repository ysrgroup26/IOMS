<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\HseInspection;
use App\Models\User;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.110.0 -- HSE: the inspection register.
 *
 * The per-inspection report (`pdf.hse-inspection`) is the record of ONE
 * inspection. This is the other document an auditor asks for: everything
 * inspected in a period, with its result -- the evidence that inspections
 * are happening at all, which no single report can show.
 *
 * `open_actions` is counted from the real CorrectiveAction rows, not
 * stored, so a register can never claim a finding was closed because a
 * counter was not updated.
 */
class HseInspectionRegisterDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'hse-inspection-register';
    }

    public function title(): string
    {
        return 'HSE Inspection Register';
    }

    public function description(): string
    {
        return 'Rekapitulasi inspeksi HSE beserta hasil dan tindakan perbaikan yang masih terbuka.';
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
            ['key' => 'inspection_number', 'label' => 'Inspection No.', 'type' => 'text', 'width' => 20],
            ['key' => 'inspection_date', 'label' => 'Date', 'type' => 'date', 'width' => 14],
            ['key' => 'inspection_type', 'label' => 'Type', 'type' => 'text', 'width' => 16],
            ['key' => 'company', 'label' => 'Operating Unit', 'type' => 'text', 'width' => 22],
            ['key' => 'project', 'label' => 'Project', 'type' => 'text', 'width' => 22],
            ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'width' => 22],
            ['key' => 'inspector', 'label' => 'Inspector', 'type' => 'text', 'width' => 20],
            ['key' => 'overall_result', 'label' => 'Result', 'type' => 'text', 'width' => 12],
            ['key' => 'open_actions', 'label' => 'Open Actions', 'type' => 'number', 'width' => 14],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = HseInspection::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('company:id,name', 'project:id,name', 'inspector:id,name')
            ->withCount(['correctiveActions as open_actions_count' => fn ($q) => $q->whereIn('status', ['open', 'in_progress'])])
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('inspection_type', $v))
            ->when($filters['result'] ?? null, fn ($q, $v) => $q->where('overall_result', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('inspection_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('inspection_date', '<=', $v))
            ->orderByDesc('inspection_date');

        foreach ($query->cursor() as $inspection) {
            yield [
                'inspection_number' => $inspection->inspection_number,
                'inspection_date' => $inspection->inspection_date,
                'inspection_type' => ucwords(str_replace('_', ' ', (string) $inspection->inspection_type)),
                'company' => $inspection->company?->name,
                'project' => $inspection->project?->name,
                'location' => $inspection->location,
                'inspector' => $inspection->inspector?->name,
                'overall_result' => strtoupper((string) $inspection->overall_result),
                'open_actions' => $inspection->open_actions_count,
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Tipe' => ($filters['type'] ?? null) ? ucwords(str_replace('_', ' ', $filters['type'])) : null,
            'Hasil' => ($filters['result'] ?? null) ? strtoupper($filters['result']) : null,
            'Periode' => ($filters['from'] ?? null) || ($filters['to'] ?? null)
                ? trim(($filters['from'] ?? '...').' s/d '.($filters['to'] ?? '...'))
                : null,
        ];
    }

    public function authorize(User $user): bool
    {
        // Same gate as hse-inspections.index, which is authentication
        // only -- management of inspections is a separate question from
        // reading the register.
        return true;
    }
}
