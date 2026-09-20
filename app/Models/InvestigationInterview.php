<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * v2.73.0 -- one interview conducted during an HSE investigation.
 *
 * See the owning migration for why an interview is a row while an initial
 * report's witness is a line of JSON: the same person recorded at two
 * different levels of formality, which is the whole shape of the
 * report/investigation separation.
 */
class InvestigationInterview extends Model
{
    use BelongsToCompany;

    /**
     * Why this person was spoken to. It changes how their account is
     * weighed -- an involved party and a passing witness are not
     * describing the event from the same position, and an investigation
     * that loses that distinction ends up averaging two incompatible
     * stories.
     */
    public const RELATIONSHIPS = ['witness', 'involved_party', 'supervisor', 'first_responder', 'subject_matter_expert', 'other'];

    protected $fillable = [
        'incident_investigation_id', 'company_id', 'employee_id',
        'person_name', 'person_role', 'relationship',
        'interviewed_on', 'interviewed_by', 'statement', 'investigator_notes',
    ];

    protected function casts(): array
    {
        return ['interviewed_on' => 'date'];
    }

    public function investigation()
    {
        return $this->belongsTo(IncidentInvestigation::class, 'incident_investigation_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function interviewer()
    {
        return $this->belongsTo(User::class, 'interviewed_by');
    }
}
