<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompanyThrough;

use Illuminate\Database\Eloquent\Model;

class ProjectManpower extends Model
{
    use BelongsToCompanyThrough;

    /** Ownership resolves through this relation -- see BelongsToCompanyThrough. */
    protected string $companyOwnerRelation = 'project';

    protected $table = 'project_manpower';

    protected $fillable = ['project_id', 'employee_id', 'assigned_date', 'added_by'];

    protected function casts(): array
    {
        return ['assigned_date' => 'date'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function addedBy()
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
