<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.73.0 -- WITNESS AND INTERVIEW RECORDS, as rows rather than JSON.
     *
     * The initial report keeps its witnesses as JSON, and deliberately:
     * there, a witness is a name and a phone number written down at the
     * scene, captured once and never revised.
     *
     * An INTERVIEW is a different object. It happens on a date, with a
     * person, conducted by a named investigator, and produces a statement
     * that is quoted in the conclusion and may be contradicted by another
     * one. It gets added to over days, it is evidence, and "who did we
     * still not speak to" is a real question. That is a table.
     *
     * The split is not inconsistency -- it is the same fact at two
     * different levels of formality, which is the whole shape of the
     * initial-report / investigation separation this release is about.
     */
    public function up(): void
    {
        Schema::create('investigation_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_investigation_id')->constrained()->cascadeOnDelete();

            // Carried directly rather than reached through the
            // investigation, for the same uniform-tenant-safety reason
            // `corrective_actions` and `incident_investigations` both
            // carry it: a scope that has to traverse a relation is a scope
            // that gets forgotten.
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();

            // The person interviewed. An Employee where they are on the
            // workforce; a plain name where they are a contractor,
            // visitor or member of the public.
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('person_name');
            $table->string('person_role')->nullable();
            $table->string('relationship')->nullable(); // witness, involved_party, supervisor, first_responder, subject_matter_expert

            $table->date('interviewed_on')->nullable();
            $table->foreignId('interviewed_by')->nullable()->constrained('users')->nullOnDelete();

            // The statement itself, and what the investigator drew from
            // it. Kept apart on purpose: what somebody said and what it
            // means are different claims, and merging them into one field
            // is how an interpretation ends up quoted as testimony.
            $table->text('statement')->nullable();
            $table->text('investigator_notes')->nullable();

            $table->timestamps();

            // Named explicitly: the derived name would be
            // `investigation_interviews_company_id_incident_investigation_id_index`,
            // 66 characters, and MySQL rejects an identifier over 64.
            // SQLite does not, so the suite would have passed and only a
            // real MySQL migration would have failed.
            $table->index(['company_id', 'incident_investigation_id'], 'inv_interviews_company_investigation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_interviews');
    }
};
