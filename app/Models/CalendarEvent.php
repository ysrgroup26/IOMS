<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** v1.11.0 (SaaS Finalization Pass). Manual calendar events only -- see the owning migration's own doc comment for why. */
class CalendarEvent extends Model
{
    public const TYPES = ['general', 'meeting', 'deadline', 'reminder'];

    protected $fillable = [
        'company_id', 'title', 'description', 'start_at', 'end_at', 'all_day',
        'event_type', 'department_key', 'responsible_user_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
