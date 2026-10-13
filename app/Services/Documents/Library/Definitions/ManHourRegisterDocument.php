<?php

namespace App\Services\Documents\Library\Definitions;

use App\Models\Company;
use App\Models\ManHourLog;
use App\Models\Project;
use App\Models\User;
use App\Services\Documents\Library\DocumentDefinition;
use App\Services\Documents\Library\DocumentRenderer;

/**
 * v2.112.0 -- Management: the man-hour register.
 *
 * The exposure base, and the one number an HSE rate cannot be computed
 * without. THIS DOCUMENT REPORTS HOURS, NEVER A RATE: it is the raw
 * record a rate is built FROM, so nothing printed here can quietly become
 * a safety statistic that leaves the building without its denominator.
 *
 * v2.116.0 -- the HSE Overview now publishes TRIR and LTIFR, with the
 * numerator, the multiplier and this very denominator printed beside them
 * (ADR 056). That does not change this document: a rate is publishable
 * where its inputs are visible on the same screen, and a spreadsheet that
 * travels by email is the opposite of that.
 *
 * Hours are written as decimals because that is what they are: half an
 * hour is 0.5, and a register that rounded it would not reconcile with
 * payroll.
 */
class ManHourRegisterDocument implements DocumentDefinition
{
    public function key(): string
    {
        return 'man-hour-register';
    }

    public function title(): string
    {
        return 'Man-Hour Register';
    }

    public function description(): string
    {
        return 'Catatan jam kerja per karyawan dan proyek. Dokumen ini melaporkan jam kerja, bukan angka LTIFR atau TRIR.';
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
            ['key' => 'work_date', 'label' => 'Work Date', 'type' => 'date', 'width' => 14],
            ['key' => 'employee_id', 'label' => 'Employee ID', 'type' => 'text', 'width' => 16],
            ['key' => 'employee', 'label' => 'Employee', 'type' => 'text', 'width' => 26],
            ['key' => 'company', 'label' => 'Operating Unit', 'type' => 'text', 'width' => 22],
            ['key' => 'project', 'label' => 'Project', 'type' => 'text', 'width' => 24],
            ['key' => 'regular_hours', 'label' => 'Regular Hours', 'type' => 'decimal', 'width' => 16],
            ['key' => 'overtime_hours', 'label' => 'Overtime Hours', 'type' => 'decimal', 'width' => 16],
            ['key' => 'total_hours', 'label' => 'Total Hours', 'type' => 'decimal', 'width' => 16],
        ];
    }

    public function rows(array $filters = []): iterable
    {
        $query = ManHourLog::query()
            ->whereIn('company_id', Company::query()->pluck('id'))
            ->with('company:id,name', 'project:id,name', 'employee:id,employee_id,full_name')
            ->when($filters['project_id'] ?? null, fn ($q, $v) => $q->where('project_id', (int) $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('work_date', '<=', $v))
            ->orderByDesc('work_date');

        foreach ($query->cursor() as $log) {
            yield [
                'work_date' => $log->work_date,
                'employee_id' => $log->employee?->employee_id,
                'employee' => $log->employee?->full_name,
                'company' => $log->company?->name,
                'project' => $log->project?->name,
                'regular_hours' => $log->regular_hours,
                'overtime_hours' => $log->overtime_hours,
                // Computed, never stored: a stored total is a total that
                // can disagree with the two columns beside it.
                'total_hours' => (float) $log->regular_hours + (float) $log->overtime_hours,
            ];
        }
    }

    public function refs(array $filters = []): array
    {
        return [
            'Proyek' => isset($filters['project_id']) ? Project::find($filters['project_id'])?->name : null,
            'Periode' => DocumentLabel::period($filters),
        ];
    }

    public function authorize(User $user): bool
    {
        return true;
    }
}
