<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.112.0 -- Warehouse & Logistics: the material request register.
 *
 * `items_count` and `completed_at` come straight off the record. The
 * STATUS is the stored one and nothing here derives a different answer
 * from it: "Pending Approval" is deliberately not a stored status in this
 * codebase (ADR 006), and a register that invented one would disagree
 * with every other surface in the product.
 */
class MaterialRequestRegisterDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'material-request-register';
    }

    public function title(): string
    {
        return 'Material Request Register';
    }

    public function description(): string
    {
        return 'Rekapitulasi permintaan material beserta departemen, proyek, jumlah item dan statusnya.';
    }

    public function workspace(): string
    {
        return 'logistics';
    }

    public function formats(): array
    {
        return DocumentRenderer::FORMATS;
    }

    public function columns(): array
    {
        return [
            ['key' => 'request_number', 'label' => 'Request No.', 'type' => 'text', 'width' => 20],
            ['key' => 'request_date', 'label' => 'Date', 'type' => 'date', 'width' => 14],
            ['key' => 'company', 'label' => 'Operating Unit', 'type' => 'text', 'width' => 22],
            ['key' => 'department', 'label' => 'Department', 'type' => 'text', 'width' => 20],
            ['key' => 'project', 'label' => 'Project', 'type' => 'text', 'width' => 22],
            ['key' => 'requester', 'label' => 'Requested By', 'type' => 'text', 'width' => 20],
            ['key' => 'items', 'label' => 'Items', 'type' => 'number', 'width' => 10],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 16],
            ['key' => 'completed_at', 'label' => 'Completed', 'type' => 'date', 'width' => 14],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = MaterialRequest::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('company:id,name', 'department:id,name', 'project:id,name', 'requester:id,name')
            ->withCount('items')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('request_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('request_date', '<=', $v))
            ->orderByDesc('request_date');

        foreach ($query->cursor() as $request) {
            yield [
                'request_number' => $request->request_number,
                'request_date' => $request->request_date,
                'company' => $request->company?->name,
                'department' => $request->department?->name,
                'project' => $request->project?->name,
                'requester' => $request->requester?->name,
                'items' => $request->items_count,
                'status' => DocumentLabel::humanize($request->status),
                'completed_at' => $request->completed_at,
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
