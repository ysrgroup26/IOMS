<?php

namespace App\Models;

use App\Concerns\HasWorkflow;
use App\Models\Concerns\BelongsToCompany;
use App\Services\NumberGeneratorService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Milestone 4, Workstream B6 (Permit To Work). See the owning migration's
 * doc comment (2026_08_20_100067) for the deliberate "required_qualification
 * is optional, never auto-checked" design.
 */
class PermitToWork extends Model
{
    use BelongsToCompany, HasWorkflow, SoftDeletes;

    // Production bug fix: Eloquent's default table-name inference
    // pluralizes "PermitToWork" as `permit_to_works` (naive last-word
    // pluralization), but the owning migration
    // (2026_08_20_100067_create_permits_to_work_table) -- and the FKs in
    // gas_test_records/loto_records that reference it -- all use
    // `permits_to_work`. Explicit $table makes the model match the real,
    // already-migrated table instead of the other way around.
    protected $table = 'permits_to_work';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_REJECTED = 'rejected';

    /**
     * v2.127.0 -- HSE HAS VERIFIED IT; THE APPROVER HAS NOT SIGNED IT YET.
     *
     * The second gate. A permit used to go straight from `submitted` to
     * `approved` on one `canManageHse()` decision; the owner's product
     * requirement is that HSE VERIFIES and somebody else APPROVES, and
     * that a permit is not ACTIVE until both have happened.
     *
     * This state is the gap between them, and it is a real operational
     * position rather than a label: the work is checked and is still not
     * authorised to begin. See ADR 067.
     */
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPES = ['hot_work', 'cold_work', 'confined_space', 'working_at_height', 'excavation', 'electrical', 'general'];

    /*
     * v2.127.0 -- `submitted` NO LONGER REACHES `approved`.
     *
     * That single edge is what made skipping verification possible, and
     * removing it is what makes the sequence a rule rather than a
     * convention: the state machine itself now refuses
     * submitted -> approved, so no controller, console command, test or
     * future caller can produce an approved permit that nobody verified.
     * `HasWorkflow::transitionTo()` throws a validation exception naming
     * what IS allowed, which is a better refusal than a 403 because the
     * problem is a business rule and not a permission.
     *
     * Rejection stays reachable from BOTH gates: a verifier may send a
     * permit back, and so may an approver who disagrees with a
     * verification. Cancellation likewise.
     */
    protected static array $transitions = [
        self::STATUS_DRAFT => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED => [self::STATUS_VERIFIED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_VERIFIED => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_REJECTED => [self::STATUS_DRAFT, self::STATUS_CANCELLED],
        self::STATUS_APPROVED => [self::STATUS_ACTIVE, self::STATUS_CANCELLED],
        self::STATUS_ACTIVE => [self::STATUS_CLOSED],
        self::STATUS_CLOSED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'ptw_number', 'company_id', 'project_id', 'work_reference', 'risk_assessment_id', 'jsa_id',
        'permit_type', 'work_description', 'location', 'start_datetime', 'end_datetime',
        'required_qualification', 'precautions', 'requested_by', 'area_authority_id',
        // v2.127.0 -- the verification decision, separate from the
        // approval one. NOT mass-assigned from any request: both are
        // written server-side in PermitToWorkController::transition(),
        // the same discipline `hse_approver_id` has always had.
        'hse_verifier_id', 'verified_at', 'approved_at',
        // v2.17.0 (PTW Field Workflow Foundation, Part 8): PIC / Supervisor
        // Lapangan -- a separate person from `requested_by` (see this
        // column's own migration doc comment).
        'pic_employee_id',
        'hse_approver_id', 'closed_by', 'closed_at', 'status',
        // v2.73.0 -- PPE and the hazards it protects against. See the
        // owning migration for why these are JSON snapshots of `ppe_types`
        // ids rather than a pivot table.
        'required_ppe_ids', 'confirmed_ppe_ids', 'additional_ppe', 'hazards',
    ];

    protected function casts(): array
    {
        return [
            'start_datetime' => 'datetime',
            'end_datetime' => 'datetime',
            'closed_at' => 'datetime',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'required_ppe_ids' => 'array',
            'confirmed_ppe_ids' => 'array',
            'additional_ppe' => 'array',
            'hazards' => 'array',
        ];
    }

    /**
     * v2.73.0 -- PPE REQUIRED BY THIS PERMIT, RESOLVED FROM THE MASTER.
     *
     * Not an Eloquent relation, deliberately. `required_ppe_ids` is a
     * SNAPSHOT of what this permit called for at the time it was raised:
     * deactivating a PPE type next year must not silently rewrite what
     * last year's permit said, and a `belongsToMany` would make exactly
     * that happen. Resolving names for display is a lookup, not a join.
     *
     * An id whose type has since been deleted resolves to nothing and is
     * simply absent from the list -- which is honest. It is never rendered
     * as a fabricated placeholder.
     */
    public function resolvePpe(array $ids, \Illuminate\Support\Collection $types): array
    {
        return collect($ids)
            ->map(fn ($id) => $types->firstWhere('id', (int) $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Was the PPE this permit requires actually confirmed present?
     *
     * The difference between `required` and `confirmed` is the only thing
     * a permit is for -- what should be true versus what somebody checked
     * -- so the gap is computed rather than left for a reader to diff two
     * lists by eye.
     */
    public function unconfirmedPpeIds(): array
    {
        $required = collect($this->required_ppe_ids ?? [])->map(fn ($id) => (int) $id);
        $confirmed = collect($this->confirmed_ppe_ids ?? [])->map(fn ($id) => (int) $id);

        return $required->diff($confirmed)->values()->all();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function riskAssessment()
    {
        return $this->belongsTo(RiskAssessment::class);
    }

    public function jsa()
    {
        return $this->belongsTo(JobSafetyAnalysis::class, 'jsa_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function areaAuthority()
    {
        return $this->belongsTo(User::class, 'area_authority_id');
    }

    /**
     * v2.17.0 (PTW Field Workflow Foundation, Part 8). References
     * `Employee`, not `User` -- unlike `requester()`/`areaAuthority()`/
     * `hseApprover()` above, a PIC is not necessarily an IOMS login at
     * all (confirmed by audit: `Employee` has no `user_id`/`User`
     * relation in this codebase, they're deliberately separate
     * identities). Optional -- see this column's own migration comment.
     */
    public function pic()
    {
        return $this->belongsTo(Employee::class, 'pic_employee_id');
    }

    /**
     * The accountable person as this permit should PRINT them.
     *
     * v2.115.0. Employee deletion is permanent, so `pic_employee_id` can
     * be cleared long after a permit was signed. `pic_name` is the
     * tombstone `EmployeeEraser` writes at that moment -- deliberately
     * not in `$fillable`, because nothing but an erasure may set it, and
     * a request that could would be able to put any name on a safety
     * document.
     *
     * Relation first: while the employee exists, their record is the
     * truth and a rename follows them. Only once they are gone does the
     * stored text answer, which is the one case where there is nothing
     * better to say.
     */
    public function picDisplayName(): ?string
    {
        $live = trim((string) $this->pic?->full_name);

        if ($live !== '') {
            return $live;
        }

        $kept = trim((string) $this->pic_name);

        return $kept === '' ? null : $kept;
    }

    /**
     * v2.17.0 (PTW Field Workflow Foundation, Part 9). The permit's
     * overall planned workforce -- distinct from, and never duplicated
     * into, any JSA-level manpower concept (JSA has none today, confirmed
     * by audit; this pass does not add one, per "do NOT force manpower
     * into JSA simply because PTW now has it"). Always drawn from real
     * `Employee` records, never free text.
     */
    public function personnel()
    {
        return $this->belongsToMany(Employee::class, 'permit_to_work_personnel', 'permit_to_work_id', 'employee_id');
    }

    public function hseApprover()
    {
        return $this->belongsTo(User::class, 'hse_approver_id');
    }

    /** v2.127.0 -- whoever performed the HSE VERIFICATION, which is not the approver. */
    public function hseVerifier()
    {
        return $this->belongsTo(User::class, 'hse_verifier_id');
    }

    /**
     * v2.127.0 -- HAS THIS PERMIT ACTUALLY BEEN VERIFIED BY HSE?
     *
     * The predicate behind the VERIFIED stamp, shaped exactly like
     * `isAuthorised()` below and for the same reason: the screen, the
     * document view and the PDF must not be able to disagree.
     *
     * Both halves are required. A status alone could be reached by a
     * future caller that forgot to record the actor; an actor alone could
     * survive a rejection that sent the permit back to draft. A stamp
     * claims that a named person made a decision, so it renders only where
     * the record holds both.
     */
    public function isVerified(): bool
    {
        return $this->hse_verifier_id !== null && in_array($this->status, [
            self::STATUS_VERIFIED,
            self::STATUS_APPROVED,
            self::STATUS_ACTIVE,
            self::STATUS_CLOSED,
        ], true);
    }

    /**
     * v2.72.0 -- HAS THIS PERMIT ACTUALLY BEEN AUTHORISED BY HSE?
     *
     * The single predicate behind the approval stamp on the screen, the
     * document view and the PDF. It exists as a model method precisely so
     * those three cannot disagree: a controlled document that says
     * APPROVED in one renderer and not in another is worse than one that
     * says nothing.
     *
     * DERIVED FROM PERSISTED STATE ONLY. Both halves are required -- a
     * status alone is not authorisation, because `hse_approver_id` is
     * what records WHO exercised it. Neither is settable from a form;
     * `hse_approver_id` is written server-side in
     * PermitToWorkController::transition(), gated on canManageHse().
     *
     * WHY `active` AND `closed` COUNT. Approval is a fact about the
     * permit's history, not its current step: a permit that was approved
     * and is now being worked, or has since been closed out, WAS
     * authorised, and its record must keep saying so. That is the whole
     * point of a controlled document.
     *
     * WHY `cancelled` DOES NOT. A cancelled permit is void. Stamping a
     * void instrument APPROVED would be the one genuinely dangerous thing
     * this feature could do -- it is the state most likely to be waved at
     * somebody on site as authorisation to proceed.
     */
    public function isAuthorised(): bool
    {
        return $this->hse_approver_id !== null
            && in_array($this->status, [self::STATUS_APPROVED, self::STATUS_ACTIVE, self::STATUS_CLOSED], true);
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function gasTests()
    {
        return $this->hasMany(GasTestRecord::class);
    }

    public function lotoRecords()
    {
        return $this->hasMany(LotoRecord::class);
    }

    public static function generateNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->generate('permit_to_work', $companyId);
    }

    /**
     * v2.124.0 -- the same number, NOT taken. For a create page that
     * tells the reader what will be saved; see
     * NumberGeneratorService::preview() for why a preview must never
     * consume a sequence.
     */
    public static function previewNumber(?int $companyId = null): string
    {
        return app(NumberGeneratorService::class)->preview('permit_to_work', $companyId);
    }

    /**
     * v2.52.0 -- the work's own identity, whichever way it was recorded.
     *
     * PROJECT IDENTITY AND WORK LOCATION ARE DIFFERENT THINGS, and neither
     * is optional information on a permit. `location` answers "where", and
     * this answers "what job".
     *
     * A formal Project Master is authoritative when one exists. When it
     * does not -- because Management has not created the row yet, or
     * because the work genuinely is not a project -- `work_reference`
     * carries the identity instead. What must NOT happen is the permit
     * reading "No Project" for work that plainly has a name; that is the
     * system telling an auditor it does not know what the work was, which
     * is untrue and unhelpful.
     *
     * v2.53.0: renamed from `project_name`, which read as "the name of the
     * project" and left users unable to tell it apart from the Project
     * selector directly above it.
     */
    public function workIdentity(): ?string
    {
        return $this->project?->name ?: ($this->work_reference ?: null);
    }

    /**
     * v2.125.0 -- where a notification about this permit opens.
     *
     * `permits-to-work` is on RestrictMyWorkUser's allow-list, so this
     * destination is reachable by the class that raises most permits --
     * which is the whole point: a permit returned for correction has to
     * open the permit, not a list, or the field worker cannot see the
     * reason it came back.
     *
     * @see \App\Concerns\HasWorkflow::notificationUrl()
     */
    public function notificationRouteName(): ?string
    {
        return 'permits-to-work.show';
    }

    /**
     * The product's name for this document.
     *
     * The trait derives "Permit To Work" from the class name, which is
     * correct but is not how the product writes it -- `config/modules.php`,
     * the navigation and the PDF all say "Permit to Work". A notification
     * is the one surface a field worker reads without opening the record,
     * so it is the one that must match the menu item they tapped.
     */
    public function documentLabel(): string
    {
        return 'Permit to Work';
    }

    /**
     * v2.129.0 -- WHAT THE REQUESTER IS TOLD, AT EACH STAGE, IN THEIR WORDS.
     *
     * The trait's default is "Permit to Work PTW-2026-00010 is now Active",
     * which is accurate and says nothing a person can act on. A permit has a
     * real lifecycle and each step means something different to whoever
     * raised it:
     *
     *   verified  -- HSE is satisfied. Nothing is required of you.
     *   approved  -- it is authorised; it still has to be activated.
     *   active    -- THE WORK MAY START. This is the one that matters.
     *   rejected  -- it came back, and the body is the reason why.
     *   closed    -- the job is finished and the permit is spent.
     *
     * Each title names the STATE and each body says what follows from it, so
     * the notification is readable on a lock screen without opening
     * anything. The number is in the body rather than the title because a
     * title truncated on a phone should keep the meaning, not the reference.
     *
     * English title, Indonesian body -- the language hierarchy
     * (docs/CONVENTIONS.md): a title is a label, a body is help text.
     *
     * @return array{title: string, body: ?string}
     */
    protected function statusNotificationCopy(string $newStatus, array $meta): array
    {
        $number = $this->ptw_number;
        $where = trim(implode(' · ', array_filter([
            $this->permit_type ? \Illuminate\Support\Str::headline($this->permit_type) : null,
            $this->location,
        ])));

        $copy = match ($newStatus) {
            self::STATUS_VERIFIED => [
                'title' => 'Permit verified by HSE',
                'body' => $number.' telah diverifikasi HSE dan menunggu persetujuan akhir.',
            ],
            self::STATUS_APPROVED => [
                'title' => 'Permit approved',
                'body' => $number.' telah disetujui dan siap diaktifkan.',
            ],
            self::STATUS_ACTIVE => [
                'title' => 'Permit is now active',
                'body' => $number.' telah disetujui dan sekarang ACTIVE. Pekerjaan boleh dimulai.',
            ],
            self::STATUS_REJECTED => [
                'title' => 'Permit returned for revision',
                // The reason IS the notification here, so it leads; the
                // number follows it rather than pushing it onto a second line.
                'body' => ($meta['comments'] ?? null)
                    ? $meta['comments'].' ('.$number.')'
                    : $number.' dikembalikan untuk diperbaiki.',
            ],
            self::STATUS_CLOSED => [
                'title' => 'Permit closed',
                'body' => $number.' telah ditutup.',
            ],
            self::STATUS_CANCELLED => [
                'title' => 'Permit cancelled',
                'body' => $number.' dibatalkan dan tidak dapat digunakan lagi.',
            ],
            // Submitted, draft, anything added later: the trait's own
            // sentence, which is never wrong.
            default => null,
        };

        if ($copy === null) {
            /*
             * The trait's own sentence, written out rather than reached
             * with `parent::` -- `statusNotificationCopy()` comes from a
             * TRAIT, and `parent::` on a trait method resolves to
             * Eloquent\Model, which has none. A fatal error inside a
             * notification would have taken the transition down with it.
             */
            return [
                'title' => $this->documentLabel().' '.$this->displayNumber().' is now '.$this->statusLabel($newStatus),
                'body' => $meta['comments'] ?? null,
            ];
        }

        // Where the work is, when the permit says -- one short line that
        // makes a notification about "a permit" into one about THIS job.
        if ($where !== '' && $newStatus !== self::STATUS_REJECTED) {
            $copy['body'] = $copy['body'].' '.$where.'.';
        }

        return $copy;
    }
}
