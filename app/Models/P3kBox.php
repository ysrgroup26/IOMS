<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream B12 (P3K / First Aid). See the owning migration's own doc comment on scope boundaries. */
class P3kBox extends Model
{
    public const STATUSES = ['complete', 'incomplete'];

    protected $fillable = ['company_id', 'location', 'last_inspection_date', 'next_inspection_due', 'inspected_by', 'status', 'notes'];

    protected $appends = ['is_overdue'];

    protected function casts(): array
    {
        return [
            'last_inspection_date' => 'date',
            'next_inspection_due' => 'date',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function inspector()
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->next_inspection_due && $this->next_inspection_due->isPast();
    }
}
