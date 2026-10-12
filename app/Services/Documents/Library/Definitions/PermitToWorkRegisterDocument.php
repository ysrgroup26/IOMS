<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\PermitToWork;
use App\Models\User;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.112.0 -- HSE: the permit register.
 *
 * The per-permit PDF (`pdf.permit-to-work`) is the FORM one crew carries
 * to one job. This is the other document an auditor asks for: every
 * permit raised in a period, who authorised it, and whether it was
 * closed. No single permit can show that permits are being closed at all.
 *
 * It reports the STORED status and the recorded approver, and invents
 * neither: a permit that is `approved` with no `hse_approver_id` prints
 * an empty approver, because that row genuinely records no one (the same
 * rule `ApprovalStamp` enforces on screen -- see ADR 053's "never a
 * boolean" note and v2.72.0).
 */
class PermitToWorkRegisterDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'permit-to-work-register';
    }

    public function title(): string
    {
        return 'Permit to Work Register';
    }

    public function description(): string
    {
        return 'Rekapitulasi izin kerja beserta jenis pekerjaan, masa berlaku, pemberi izin dan statusnya.';
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
            ['key' => 'ptw_number', 'label' => 'PTW No.', 'type' => 'text', 'width' => 20],
            ['key' => 'permit_type', 'label' => 'Permit Type', 'type' => 'text', 'width' => 18],
            ['key' => 'work_description', 'label' => 'Work', 'type' => 'text', 'width' => 34],
            ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'width' => 20],
            ['key' => 'project', 'label' => 'Project', 'type' => 'text', 'width' => 22],
            ['key' => 'start_datetime', 'label' => 'Valid From', 'type' => 'date', 'width' => 14],
            ['key' => 'end_datetime', 'label' => 'Valid To', 'type' => 'date', 'width' => 14],
            ['key' => 'requester', 'label' => 'Requested By', 'type' => 'text', 'width' => 20],
            ['key' => 'approver', 'label' => 'HSE Approver', 'type' => 'text', 'width' => 20],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 14],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = PermitToWork::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('company:id,name', 'project:id,name', 'requester:id,name', 'hseApprover:id,name')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['permit_type'] ?? null, fn ($q, $v) => $q->where('permit_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('start_datetime', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('start_datetime', '<=', $v))
            ->orderByDesc('start_datetime');

        foreach ($query->cursor() as $permit) {
            yield [
                'ptw_number' => $permit->ptw_number,
                'permit_type' => DocumentLabel::humanize($permit->permit_type),
                'work_description' => $permit->work_description,
                'location' => $permit->location,
                'project' => $permit->project?->name,
                'start_datetime' => $permit->start_datetime,
                'end_datetime' => $permit->end_datetime,
                'requester' => $permit->requester?->name,
                // Never inferred from the status: an approved permit with
                // no recorded approver prints empty, not "approved".
                'approver' => $permit->hseApprover?->name,
                'status' => DocumentLabel::humanize($permit->status),
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Status' => DocumentLabel::humanize($filters['status'] ?? null),
            'Jenis Izin' => DocumentLabel::humanize($filters['permit_type'] ?? null),
            'Periode' => DocumentLabel::period($filters),
        ];
    }

    public function authorize(User $user): bool
    {
        // Same gate as permits-to-work.index: anyone who may see the list
        // may print it. A foreman must be able to print their own permits.
        return true;
    }
}
