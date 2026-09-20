<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Incident Management (v1.10.0) -- HSE's first real module beyond PPE.
 * Workflow Engine only, no Approval Engine (see migration's own note).
 *
 * v2.73.0 -- THIS IS THE INITIAL REPORT, AND ONLY THAT.
 *
 * The record is filed in the minutes after an event by whoever was there.
 * Its job is 5W1H -- what happened, who was hurt, when, where, how, and
 * what was known at the time -- plus the immediate response and whatever
 * evidence could be captured on the spot. It is the factual source a
 * trained investigator later works from, and it carries the structured
 * facts an employment-injury submission asks for.
 *
 * WHAT IT DELIBERATELY DOES NOT CARRY: root cause, causal analysis,
 * methodology, corrective actions, effectiveness verification. Those are
 * `IncidentInvestigation`, which since v2.73.0 is a separate workspace
 * with its own number, workflow and closure. The separation is the
 * architecture, not a UI preference -- see
 * `docs/ADR/036-incident-report-versus-investigation.md`.
 */
class Incident extends Model
{
    use BelongsToCompany, HasWorkflow, SoftDeletes;

    public const STATUS_REPORTED = 'reported';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_CLOSED = 'closed';

    public const SEVERITIES = ['minor', 'moderate', 'major', 'critical'];

    public const CATEGORIES = ['injury', 'near_miss', 'property_damage', 'environmental', 'other'];

    /**
     * v2.73.0. Who was hurt, in the terms that matter for reporting:
     * whether they are on this tenant's workforce. `none` is first and is
     * the right answer for a near miss or property damage -- a report that
     * forces a name onto an event that injured nobody produces a
     * fictitious casualty.
     */
    public const PERSON_TYPES = ['none', 'employee', 'contractor', 'visitor', 'public'];

    /**
     * v2.73.0. Ordered by consequence, because this is the field every
     * downstream summary sorts and counts on.
     *
     * These are outcome classifications in the ordinary HSE sense. They
     * are NOT a statutory classification scheme, and nothing here should
     * be read as computing a regulatory category on a tenant's behalf --
     * what IOMS does is record, in structured form, the fact a human
     * recorded.
     */
    public const INJURY_SEVERITIES = ['none', 'first_aid', 'medical_treatment', 'restricted_work', 'lost_time', 'fatality'];

    public const INJURY_SEVERITY_LABELS = [
        'none' => 'No Injury',
        'first_aid' => 'First Aid',
        'medical_treatment' => 'Medical Treatment',
        'restricted_work' => 'Restricted Work',
        'lost_time' => 'Lost Time',
        'fatality' => 'Fatality',
    ];

    protected static array $transitions = [
        self::STATUS_REPORTED => [self::STATUS_INVESTIGATING, self::STATUS_CLOSED],
        self::STATUS_INVESTIGATING => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
    ];

    protected $fillable = [
        'incident_number',
        'title',
        'description',
        'incident_date',
        'incident_time',
        'location',
        'work_area',
        'severity',
        'category',
        'status',
        'company_id',
        'project_id',
        'reported_by',
        // v2.73.0 -- the 5W1H initial report. See the owning migration.
        'injured_employee_id',
        'injured_person_name',
        'injured_person_type',
        'injured_person_job_title',
        'injured_person_id_number',
        'injury_type',
        'body_part',
        'injury_severity',
        'people_injured',
        'immediate_treatment',
        'medical_facility',
        'referred_to_facility',
        'chronology',
        'initial_circumstances',
        'immediate_actions',
        'witnesses',
        'evidence_paths',
        'reported_at',
        'work_related',
        'reportable_to_authority',
        'employment_injury_reference',
    ];

    protected function casts(): array
    {
        return [
            'incident_date' => 'date',
            'reported_at' => 'datetime',
            'witnesses' => 'array',
            'evidence_paths' => 'array',
            'referred_to_facility' => 'boolean',
            'work_related' => 'boolean',
            'reportable_to_authority' => 'boolean',
            'people_injured' => 'integer',
        ];
    }

    /** v2.73.0 -- the injured party, where they are on this tenant's workforce. */
    public function injuredEmployee()
    {
        return $this->belongsTo(Employee::class, 'injured_employee_id');
    }

    /**
     * v2.73.0. Did this report actually injure somebody?
     *
     * Reads the severity rather than the category, because `category`
     * records the KIND of event (a near miss, property damage) and
     * severity records the OUTCOME -- and the two disagree more often
     * than people expect: a property-damage event that also cut
     * somebody's hand is both.
     */
    public function hasInjury(): bool
    {
        return $this->injury_severity !== null && $this->injury_severity !== 'none';
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /**
     * The investigation raised from this report, if one has been.
     *
     * Still one-to-one -- one event, one investigation -- but since
     * v2.73.0 the thing on the other end is a full record with its own
     * number, state and closure rather than a handful of columns. Most
     * incidents never have one, and that is correct: an investigation is
     * started deliberately, by HSE, not created automatically alongside
     * every report.
     */
    public function investigation()
    {
        return $this->hasOne(IncidentInvestigation::class);
    }

    /** Milestone 4, Workstream B14/B15 -- reusable CAPA, same entity Safety Observation/HSE Inspection already use. */
    public function correctiveActions()
    {
        return $this->morphMany(CorrectiveAction::class, 'source');
    }

    /** INC-{YEAR}-{00001}, same per-year sequential convention as Material Request/Leave. */
    /**
     * Milestone 3: delegates to the centralized, lock-safe Numbering
     * Engine -- see MaterialRequest::generateRequestNumber()'s doc
     * comment for why. Same INC-{YEAR}-{00001} shape as before by default.
     */
    public static function generateIncidentNumber(?int $companyId = null): string
    {
        return app(\App\Services\NumberGeneratorService::class)->generate('incident', $companyId);
    }
}
