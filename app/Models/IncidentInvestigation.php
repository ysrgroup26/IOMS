<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Milestone 4, Workstream B14. See the owning migration's own doc comment. */
class IncidentInvestigation extends Model
{
    public const METHODS = ['5_why', 'fishbone', 'other'];

    protected $fillable = [
        'incident_id', 'company_id', 'method', 'root_cause', 'findings',
        'recommendations', 'investigator_id', 'investigated_at',
    ];

    protected function casts(): array
    {
        return ['investigated_at' => 'date'];
    }

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function investigator()
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }
}
