<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.73.0 -- INVESTIGATION BECOMES A RECORD OF ITS OWN.
     *
     * `incident_investigations` shipped in Milestone 4 as a one-to-one
     * enhancement of an incident: method, root_cause, findings,
     * recommendations, investigator, date. Seven columns, no number, no
     * status, no team, no evidence, no closure -- and, tellingly, no
     * routes of its own. It was reachable only as a card on the Incident
     * page, which is exactly how it was thought about: a few extra fields
     * on the incident rather than a separate piece of work.
     *
     * THAT FRAMING IS THE THING BEING CORRECTED. An investigation is not
     * more detail about an incident. It is a distinct activity, started
     * deliberately, done by different (trained) people, over days or
     * weeks, with its own state, its own evidence, its own conclusion and
     * its own sign-off. Modelling it as an attribute of the report makes
     * several real things impossible to express: an investigation that is
     * open while the incident is closed for reporting purposes, an
     * investigator who is not the person who filed the report, a draft
     * conclusion awaiting review, or simply the question "which
     * investigations are still open" -- which had no query at all.
     *
     * See docs/ADR/036 for the full reasoning, including why the initial
     * report deliberately carries none of this.
     *
     * EXISTING DATA IS MIGRATED, NOT DROPPED. The old table's five
     * meaningful columns all have a home in the new shape, and this
     * migration carries every row across with a generated number and a
     * status derived from whether the old record actually had a
     * conclusion in it. `down()` reverses that faithfully for the columns
     * the old table had.
     *
     * ON METHODOLOGY. `method` becomes an optional analysis framework --
     * 5 Why, Fishbone, SCAT, structured RCA, or none -- chosen per
     * investigation because a trapped-finger and a dropped-load do not
     * warrant the same instrument. These are METHODOLOGIES, not legal
     * requirements: no Indonesian regulation names SCAT or TapRooT, and
     * IOMS must not imply otherwise. What the regulations require is that
     * an investigation happens, is recorded and leads to action -- which
     * is what the workflow below enforces, independently of which
     * technique the investigator reaches for.
     */
    public function up(): void
    {
        Schema::table('incident_investigations', function (Blueprint $table) {
            // IDENTITY. An investigation people refer to by number, the
            // same way they refer to INC-2026-00001. Nullable for the
            // length of this migration only -- backfilled below, then
            // made unique.
            $table->string('investigation_number')->nullable()->after('id');

            // STATE. The whole reason this is a workspace: an
            // investigation is in progress for a while, and that has to be
            // visible and queryable.
            $table->string('status')->default('draft')->after('company_id');

            // SCOPE AND TEAM. `team` is JSON of user ids: an investigation
            // team is a list of people recorded on this document, not an
            // entity with its own lifecycle, and a pivot table would buy
            // nothing but joins. `investigator_id` remains the single
            // accountable lead.
            $table->text('scope')->nullable()->after('status');
            $table->json('team')->nullable()->after('scope');
            $table->date('started_at')->nullable()->after('team');
            $table->date('target_completion_date')->nullable()->after('started_at');

            // THE CAUSAL CHAIN, as three distinct layers rather than one
            // `root_cause` textarea. This is the substantive difference
            // between recording a conclusion and doing an analysis: the
            // immediate cause (what directly produced the harm), the
            // basic/underlying causes (the conditions that allowed it),
            // and the root cause (the system failure that let those
            // conditions exist). Keeping them separate is what stops
            // "operator error" being filed as a root cause.
            $table->text('immediate_causes')->nullable()->after('method');
            $table->text('basic_causes')->nullable()->after('immediate_causes');
            // `root_cause` and `findings` already exist and keep their
            // meaning.
            $table->text('contributing_factors')->nullable()->after('root_cause');

            // The analysis working itself -- the 5-Why chain, the fishbone
            // categories, the SCAT codes. JSON because its shape is
            // decided by `method`, and a column per methodology would be a
            // schema change every time a new one is adopted.
            $table->json('analysis')->nullable()->after('contributing_factors');

            // EVIDENCE AND THE CHRONOLOGY THE INVESTIGATOR BUILDS, which
            // is a different artefact from the reporter's account on the
            // initial report: it is reconstructed, corroborated and often
            // contradicts the first telling. Both are kept.
            $table->text('detailed_chronology')->nullable()->after('analysis');
            $table->json('evidence_paths')->nullable()->after('detailed_chronology');

            // CONCLUSION AND SIGN-OFF. `conclusion` is the investigator's
            // summary judgement; the reviewer fields record that somebody
            // other than the author accepted it, which is the point of a
            // review.
            $table->text('conclusion')->nullable()->after('recommendations');
            $table->foreignId('reviewed_by')->nullable()->after('investigator_id')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable()->after('reviewed_by');
            $table->foreignId('closed_by')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('closed_at')->nullable()->after('closed_by');

            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        // Carry every existing row across. An old investigation that had
        // a conclusion recorded in it was, in practice, a finished piece
        // of work; one that was still blank was not. Deriving the status
        // from the content is more faithful than defaulting all of them
        // to 'draft' and more honest than defaulting them to 'closed'.
        $rows = DB::table('incident_investigations')->orderBy('id')->get(['id', 'root_cause', 'findings', 'created_at']);
        $seq = 0;

        foreach ($rows as $row) {
            $seq++;
            $year = $row->created_at ? substr((string) $row->created_at, 0, 4) : date('Y');
            $hasConclusion = filled($row->root_cause) || filled($row->findings);

            DB::table('incident_investigations')->where('id', $row->id)->update([
                'investigation_number' => sprintf('INV-%s-%05d', $year, $seq),
                'status' => $hasConclusion ? 'completed' : 'in_progress',
            ]);
        }

        // Only now that every row has one can the number be required.
        // Split into its own Schema::table() call because the backfill has
        // to happen between the two.
        Schema::table('incident_investigations', function (Blueprint $table) {
            $table->string('investigation_number')->nullable(false)->change();
            $table->unique('investigation_number');
        });
    }

    public function down(): void
    {
        // The (company_id, status) index added by up() is the ONLY index
        // on this table that starts with company_id, so InnoDB adopted it
        // to satisfy that column's own foreign key -- and then refuses to
        // let it be dropped ("needed in a foreign key constraint").
        // Giving the FK a single-column index of its own first makes the
        // composite droppable. Rolled back and re-applied against MySQL
        // to confirm, because SQLite -- which the suite runs on -- has no
        // such rule and would have shown nothing.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('incident_investigations', function (Blueprint $table) {
                $table->index('company_id', 'incident_investigations_company_id_index');
            });
        }

        Schema::table('incident_investigations', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'status']);
            $table->dropUnique(['investigation_number']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropSoftDeletes();
            $table->dropColumn([
                'investigation_number', 'status', 'scope', 'team', 'started_at',
                'target_completion_date', 'immediate_causes', 'basic_causes',
                'contributing_factors', 'analysis', 'detailed_chronology',
                'evidence_paths', 'conclusion', 'reviewed_at', 'closed_at',
            ]);
        });
    }
};
