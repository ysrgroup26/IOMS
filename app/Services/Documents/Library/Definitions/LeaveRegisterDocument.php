<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/** v2.112.0 -- People / HRD: the leave register. */
class LeaveRegisterDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'leave-register';
    }

    public function title(): string
    {
        return 'Leave Register';
    }

    public function description(): string
    {
        return 'Rekapitulasi pengajuan cuti beserta jenis, periode, jumlah hari dan statusnya.';
    }

    public function workspace(): string
    {
        return 'hr';
    }

    public function formats(): array
    {
        return DocumentRenderer::FORMATS;
    }

    public function columns(): array
    {
        return [
            ['key' => 'leave_number', 'label' => 'Leave No.', 'type' => 'text', 'width' => 20],
            ['key' => 'employee_id', 'label' => 'Employee ID', 'type' => 'text', 'width' => 16],
            ['key' => 'employee', 'label' => 'Employee', 'type' => 'text', 'width' => 26],
            ['key' => 'company', 'label' => 'Operating Unit', 'type' => 'text', 'width' => 22],
            ['key' => 'leave_type', 'label' => 'Leave Type', 'type' => 'text', 'width' => 18],
            ['key' => 'start_date', 'label' => 'From', 'type' => 'date', 'width' => 14],
            ['key' => 'end_date', 'label' => 'To', 'type' => 'date', 'width' => 14],
            ['key' => 'days', 'label' => 'Days', 'type' => 'number', 'width' => 10],
            ['key' => 'status', 'label' => 'Status', 'type' => 'text', 'width' => 14],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = LeaveRequest::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('company:id,name', 'employee:id,employee_id,full_name')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['leave_type'] ?? null, fn ($q, $v) => $q->where('leave_type', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('start_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('end_date', '<=', $v))
            ->orderByDesc('start_date');

        foreach ($query->cursor() as $leave) {
            yield [
                'leave_number' => $leave->leave_number,
                // Text, explicitly: an employee number's leading zero is
                // the same value Excel would otherwise eat.
                'employee_id' => $leave->employee?->employee_id,
                'employee' => $leave->employee?->full_name,
                'company' => $leave->company?->name,
                'leave_type' => DocumentLabel::humanize($leave->leave_type),
                'start_date' => $leave->start_date,
                'end_date' => $leave->end_date,
                'days' => $leave->days,
                'status' => DocumentLabel::humanize($leave->status),
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Status' => DocumentLabel::humanize($filters['status'] ?? null),
            'Jenis Cuti' => DocumentLabel::humanize($filters['leave_type'] ?? null),
            'Periode' => DocumentLabel::period($filters),
        ];
    }

    public function authorize(User $user): bool
    {
        return true;
    }
}
