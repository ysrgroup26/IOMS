<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A project may have multiple Daily Reports on the same date -- e.g.
 * different departments or shifts reporting separately. Each report
 * represents a DEPARTMENT (free text, v1.5.1 -- no master list to
 * maintain, every company can type their own department names), not an
 * individual. Deliberately has NO manpower or PPE fields -- those live in
 * project_manpower and employee_ppe. On creation, this writes a summary
 * event to project_timeline_events (see DailyReportController@store) so
 * the Project Timeline is derived from this module rather than re-entered
 * separately.
 */
class DailyReport extends Model
{
    protected $fillable = [
        'project_id',
        'department_name',
        'report_date',
        'report_type',
        'findings',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Historical only: reports created before v1.5.1 were attributed to a
     * specific Employee ("HSE Officer"). New reports use `department_name`
     * instead (see class docblock) -- this relation is kept so old
     * records don't lose their original attribution, but nothing in the
     * current UI creates or displays through it anymore.
     */
    public function hseOfficer()
    {
        return $this->belongsTo(Employee::class, 'hse_officer_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activities()
    {
        return $this->hasMany(DailyReportActivity::class)->orderBy('sort_order');
    }

    public function photos()
    {
        return $this->hasMany(DailyReportPhoto::class);
    }

    /**
     * Ready-for-future-use hook: a plain-text summary suitable for
     * "Copy to Clipboard", WhatsApp share, or as PDF content -- none of
     * those integrations are wired up yet, but building the formatter now
     * means the frontend can call an endpoint that returns this without
     * any model changes later.
     */
    public function shareableSummary(): string
    {
        $lines = [
            "Daily Report — {$this->project?->name}",
            $this->report_date->format('d M Y').' · '.ucfirst($this->report_type),
            'Department: '.($this->department_name ?? $this->hseOfficer?->full_name ?? '—'),
            '',
            'Activities:',
        ];

        foreach ($this->activities as $activity) {
            $lines[] = "- {$activity->description}";
        }

        if ($this->findings) {
            $lines[] = '';
            $lines[] = "Findings: {$this->findings}";
        }

        if ($this->notes) {
            $lines[] = '';
            $lines[] = "Notes: {$this->notes}";
        }

        return implode("\n", $lines);
    }
}
