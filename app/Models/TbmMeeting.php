<?php

namespace App\Models;

use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Milestone 4, Workstream B3 (TBM). See the owning migration's own doc comment. */
class TbmMeeting extends Model
{
    use SoftDeletes;

    public const STATUS_CONDUCTED = 'conducted';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['tbm_number', 'company_id', 'project_id', 'topic', 'meeting_date', 'location', 'conducted_by', 'notes', 'status'];

    protected function casts(): array
    {
        return ['meeting_date' => 'date'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function conductor()
    {
        return $this->belongsTo(User::class, 'conducted_by');
    }

    public function attendees()
    {
        return $this->belongsToMany(Employee::class, 'tbm_attendees')->withTimestamps();
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('tbm', $companyId);
    }
}
