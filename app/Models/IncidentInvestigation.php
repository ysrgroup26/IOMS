<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * v2.73.0 -- THE HSE INVESTIGATION, as a record in its own right.
 *
 * Until this release an investigation was five nullable columns hanging
 * off an incident, edited from a card on the incident page. It is now a
 * workspace: its own number, its own workflow, its own team, evidence,
 * interviews, causal analysis, conclusion and closure. See
 * `docs/ADR/036-incident-report-versus-investigation.md` for why that
 * separation is the point rather than a cosmetic split.
 *
 * WHAT STAYS ON THE INCIDENT, deliberately: everything a person at the
 * scene reports. The initial report answers what happened, to whom, when,
 * where and what was done about it immediately. It never carries a root
 * cause, a methodology or a corrective action, because the person filing
 * it is not in a position to determine any of those and a form that asks
 * them to invites a guess that later gets quoted as a finding.
 *
 * THE RELATIONSHIP IS ONE-TO-ONE and stays that way: `incident_id` is
 * unique. One event, one investigation. Where an investigation spans
 * several events the practice is to investigate the most serious and
 * reference the others, not to fan one record across many.
 */
class IncidentInvestigation extends Model
{
    use BelongsToCompany, HasWorkflow, SoftDeletes;

    /** Opened but not yet being worked -- the investigator has been named, nothing else. */
    public const STATUS_DRAFT = 'draft';

    /** Evidence, interviews and analysis are being gathered. */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /** The investigator has reached a conclusion and is asking for it to be reviewed. */
    public const STATUS_UNDER_REVIEW = 'under_review';

    /** Reviewed and accepted. Findings stand; corrective actions may still be open. */
    public const STATUS_COMPLETED = 'completed';

    /** Completed AND its corrective actions dealt with. The end of the line. */
    public const STATUS_CLOSED = 'closed';

    /** Opened and then stood down -- duplicate, or escalated elsewhere. Never a conclusion. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Review is a real step, not a formality: `under_review -> in_progress`
     * exists because a reviewer sending work back is the normal outcome of
     * a review that found something, and a workflow without that path
     * quietly teaches reviewers to approve.
     *
     * `completed -> closed` is separate from completion because the
     * findings can be settled while the actions they generated are not.
     * Closing while a corrective action is still open is the single most
     * common way an investigation becomes paperwork, so the two are
     * distinct states and the UI says which is which.
     */
    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_UNDER_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW => [self::STATUS_COMPLETED, self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [self::STATUS_CLOSED, self::STATUS_IN_PROGRESS],
        self::STATUS_CLOSED => [],
        self::STATUS_CANCELLED => [],
    ];

    /**
     * ANALYSIS METHODOLOGIES, NOT LEGAL REQUIREMENTS.
     *
     * To be precise, because this is easy to overstate and IOMS must not:
     * Permenaker No. 03/MEN/1998 requires that accidents be reported and
     * examined; PP 50/2012 (SMK3) requires investigation and follow-up as
     * part of the management system; SNI ISO 45001:2018 requires incident
     * investigation and determination of root causes. NONE of them names
     * 5 Why, Fishbone, SCAT or TapRooT, and nothing in this product should
     * suggest that picking one of these satisfies a regulation.
     *
     * They are instruments an investigator chooses. `none` is a real and
     * often correct answer -- a straightforward event does not need a
     * technique applied to it to be understood, and forcing one produces
     * filled-in boxes rather than insight.
     *
     * `5_why`, `fishbone` and `other` are the three the old column already
     * allowed and are kept verbatim so no existing row becomes invalid.
     */
    public const METHODS = ['none', '5_why', 'fishbone', 'scat', 'rca', 'other'];

    public const METHOD_LABELS = [
        'none' => 'No formal methodology',
        '5_why' => '5 Why',
        'fishbone' => 'Fishbone (Ishikawa)',
        'scat' => 'SCAT',
        'rca' => 'Structured Root Cause Analysis',
        'other' => 'Other',
    ];

    protected $fillable = [
        'investigation_number', 'incident_id', 'company_id', 'status',
        'scope', 'team', 'started_at', 'target_completion_date',
        'method', 'analysis',
        'immediate_causes', 'basic_causes', 'root_cause', 'contributing_factors',
        'findings', 'detailed_chronology', 'evidence_paths',
        'recommendations', 'conclusion',
        'investigator_id', 'investigated_at',
        'reviewed_by', 'reviewed_at', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'investigated_at' => 'date',
            'started_at' => 'date',
            'target_completion_date' => 'date',
            'reviewed_at' => 'datetime',
            'closed_at' => 'datetime',
            'team' => 'array',
            'analysis' => 'array',
            'evidence_paths' => 'array',
        ];
    }

    public function incident()
    {
        return $this->belongsTo(Incident::class);
    }

    public function investigator()
    {
        return $this->belongsTo(User::class, 'investigator_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function interviews()
    {
        return $this->hasMany(InvestigationInterview::class);
    }

    /**
     * CAPA is raised FROM the investigation, not from the incident.
     *
     * The incident keeps its own `correctiveActions()` relation for the
     * containment actions that pre-date this release, but from v2.73.0
     * the corrective actions that matter -- the ones that address a cause
     * -- hang off the analysis that identified the cause. That is what
     * makes "did this investigation actually change anything" a question
     * the product can answer.
     *
     * Same polymorphic CorrectiveAction entity Safety Observation, HSE
     * Inspection and Incident already use. No second CAPA system.
     */
    public function correctiveActions()
    {
        return $this->morphMany(CorrectiveAction::class, 'source');
    }

    /**
     * An investigation is only genuinely finished when its own corrective
     * actions are. Used by the UI to say so before somebody closes one,
     * and by `canBeClosed()` below.
     */
    public function openCorrectiveActionCount(): int
    {
        return $this->correctiveActions()
            ->whereNotIn('status', [CorrectiveAction::STATUS_VERIFIED, CorrectiveAction::STATUS_CANCELLED])
            ->count();
    }

    /**
     * Deliberately advisory, not enforced in `transitionTo()`.
     *
     * An HSE manager closing an investigation with one action still open,
     * knowingly, is a judgement call they are entitled to make; a system
     * that refuses gets worked around by cancelling the action instead,
     * which destroys the record. So the UI states the position plainly and
     * lets a human decide, and the ActivityLog captures what they decided.
     */
    public function canBeClosedCleanly(): bool
    {
        return $this->openCorrectiveActionCount() === 0;
    }

    /** INV-{YEAR}-{00001}, via the same lock-safe Numbering Engine every other module uses. */
    public static function generateInvestigationNumber(?int $companyId = null): string
    {
        return app(\App\Services\NumberGeneratorService::class)->generate('incident_investigation', $companyId);
    }
}
