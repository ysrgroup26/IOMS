<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.73.0 -- THE INITIAL REPORT IS A DIFFERENT DOCUMENT FROM AN
     * INVESTIGATION, AND `incidents` WAS NEVER SHAPED LIKE ONE.
     *
     * The table shipped in v1.10.0 with title / description / date /
     * location / severity / category. That is enough to say an incident
     * happened; it is not enough to REPORT one. Everything an initial
     * report exists to capture -- who was hurt, what the injury was, what
     * first aid was given, which facility they were taken to, who saw it,
     * what was done immediately -- had nowhere to go but the free-text
     * `description`, which means it could not be listed, filtered,
     * exported or used to support an employment-injury claim.
     *
     * WHAT THIS IS FOR. An initial report is filed in the minutes and
     * hours after an event, by whoever was there, while the facts are
     * still recoverable. Its job is speed and accuracy about WHAT
     * HAPPENED, and to become the factual source record a trained
     * investigator later works from. It is deliberately NOT a root-cause
     * document: no SCAT, no 5-Why, no fishbone, no CAPA, no
     * effectiveness verification at this stage. Those belong to
     * `incident_investigations`, which v2.73.0 makes a workspace of its
     * own -- see docs/ADR/036.
     *
     * STRUCTURED AROUND 5W1H, because that is what a person at the scene
     * can actually answer, and because it maps cleanly onto both the
     * Permenaker No. 03/MEN/1998 reporting duty and the information an
     * employment-injury (BPJS Ketenagakerjaan) submission asks for. To be
     * precise about what is being claimed: IOMS is not generating a
     * statutory form here. It is capturing, as structured data, the facts
     * those processes need, so a human filling one in is transcribing
     * rather than reconstructing. See ADR 036 on keeping that distinction
     * honest.
     *
     * EVERY COLUMN IS NULLABLE. A report filed ten minutes after an event
     * will not have the medical facility yet, and refusing to save it
     * until it does is how reports stop being filed. The form asks; the
     * schema does not insist. Existing rows are untouched and remain
     * valid.
     */
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            // WHEN -- `incident_date` is a date, which cannot express "the
            // back shift, just before handover". Time is kept separate
            // rather than converted to a datetime so no existing row has
            // to be given a fabricated hour it never recorded.
            $table->time('incident_time')->nullable()->after('incident_date');

            // WHERE -- `location` already exists as free text. This is the
            // finer grain a permit or a checklist would name.
            $table->string('work_area')->nullable()->after('location');

            // WHO -- the injured/affected person. An Employee reference
            // where the person is on the workforce, plus a name field for
            // everyone who is not: contractors, visitors, a member of the
            // public. Both nullable, because a near-miss or a
            // property-damage event injures nobody at all and must not be
            // forced to name someone.
            $table->foreignId('injured_employee_id')->nullable()->after('reported_by')
                ->constrained('employees')->nullOnDelete();
            $table->string('injured_person_name')->nullable()->after('injured_employee_id');
            $table->string('injured_person_type')->nullable()->after('injured_person_name'); // employee, contractor, visitor, public, none
            $table->string('injured_person_job_title')->nullable()->after('injured_person_type');
            $table->string('injured_person_id_number')->nullable()->after('injured_person_job_title');

            // WHAT -- the injury itself, as data rather than prose,
            // because "how many people were hurt and how badly" is the
            // question every downstream report opens with.
            $table->string('injury_type')->nullable()->after('injured_person_id_number'); // laceration, fracture, burn, ...
            $table->string('body_part')->nullable()->after('injury_type');
            $table->string('injury_severity')->nullable()->after('body_part'); // first_aid, medical_treatment, restricted_work, lost_time, fatality
            $table->unsignedSmallInteger('people_injured')->nullable()->after('injury_severity');

            // The immediate response. `treatment_given` is what was done
            // at the scene; the facility fields are where the person went
            // afterwards, if anywhere.
            $table->text('immediate_treatment')->nullable()->after('people_injured');
            $table->string('medical_facility')->nullable()->after('immediate_treatment');
            $table->boolean('referred_to_facility')->nullable()->after('medical_facility');

            // HOW / WHY -- the chronology in the reporter's own words, and
            // the circumstances KNOWN AT THE TIME. Deliberately named
            // `initial_circumstances` and not `cause`: a reporter at the
            // scene is not determining causation, and a field called
            // `cause` invites them to think they are.
            $table->text('chronology')->nullable()->after('referred_to_facility');
            $table->text('initial_circumstances')->nullable()->after('chronology');

            // What was done straight away to make the area safe. This is
            // containment, not corrective action -- CAPA is raised from
            // the investigation, and conflating the two is how a
            // first-aid box getting restocked ends up recorded as a root
            // cause being addressed.
            $table->text('immediate_actions')->nullable()->after('initial_circumstances');

            // Witnesses. JSON rather than a table: a witness on an initial
            // report is a name and a contact detail captured once at the
            // scene, not an entity with a lifecycle. The INVESTIGATION's
            // interview records are the entity, and they live in their
            // own table where they can carry statements and timestamps.
            $table->json('witnesses')->nullable()->after('immediate_actions');

            // Evidence captured at the scene. Same reasoning as
            // SafetyObservation's own photo handling -- see that module.
            $table->json('evidence_paths')->nullable()->after('witnesses');

            // WHEN IT WAS REPORTED, which is not when it happened. The gap
            // between the two is itself a reportable fact, and several
            // reporting duties are expressed as a deadline measured from
            // the event.
            $table->dateTime('reported_at')->nullable()->after('evidence_paths');

            // Employment-injury reporting. A flag and a reference, not a
            // form: IOMS records THAT a claim is in play and its number so
            // the incident can be found from it later. It does not
            // generate, submit or track the claim itself, and must not
            // imply that it does.
            $table->boolean('work_related')->nullable()->after('reported_at');
            $table->boolean('reportable_to_authority')->nullable()->after('work_related');
            $table->string('employment_injury_reference')->nullable()->after('reportable_to_authority');

            // Filtering incidents by how bad and how recent is the single
            // most common HSE query, and `status` alone does not answer
            // it.
            $table->index(['company_id', 'injury_severity']);
            $table->index(['company_id', 'incident_date']);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'injury_severity']);
            $table->dropIndex(['company_id', 'incident_date']);

            // The FK has to go before its column, and Laravel needs the
            // constraint dropped by name on some drivers -- dropConstrainedForeignId
            // handles both and is a no-op on SQLite, which the suite uses.
            $table->dropConstrainedForeignId('injured_employee_id');

            $table->dropColumn([
                'incident_time', 'work_area',
                'injured_person_name', 'injured_person_type', 'injured_person_job_title',
                'injured_person_id_number', 'injury_type', 'body_part', 'injury_severity',
                'people_injured', 'immediate_treatment', 'medical_facility', 'referred_to_facility',
                'chronology', 'initial_circumstances', 'immediate_actions', 'witnesses',
                'evidence_paths', 'reported_at', 'work_related', 'reportable_to_authority',
                'employment_injury_reference',
            ]);
        });
    }
};
