<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestone 4, Workstream A (Intern/PKL detail record). Per the
     * Master Data Principle (avoid duplicate master data): interns and
     * PKL (praktik kerja lapangan / student work-placement) participants
     * are still `Employee` rows -- same shared workforce master, same
     * department/position/company relationships, same PPE assignment
     * eligibility -- NOT a second, parallel "intern" table duplicating
     * Employee's own columns. This table holds ONLY the handful of
     * fields that are genuinely specific to an intern/PKL placement and
     * meaningless for every other workforce type (institution, mentor,
     * agreement number, evaluation, completion) -- a one-to-one detail
     * extension of Employee, not a replacement for it.
     *
     * One row per Employee (`employee_id` unique) -- a given Employee
     * record is only ever intern/PKL for one placement in this system's
     * model; if the same person interns again in a later period, that
     * is a new Employee record (a new employment relationship), matching
     * how a former intern who is later hired permanently would already
     * need a new employee_id/record under this system's existing
     * employee_id-is-unique constraint.
     *
     * `cascadeOnDelete` (not restrict): if the parent Employee row is
     * ever hard-deleted (rare -- Employee uses SoftDeletes, so this only
     * fires on a genuine permanent delete, not the normal
     * archive/resign flow), this detail row has no independent meaning
     * and should go with it.
     */
    public function up(): void
    {
        // Schema::createIfMissing (not create) -- see docs/CONVENTIONS.md
        // "Known Pitfalls" / AppServiceProvider::registerSchemaMacros():
        // the standard pattern for every new-table migration in this
        // codebase since ADR-025/027, so a deploy interrupted after this
        // table is created but before it's recorded as migrated doesn't
        // fail with "table already exists" on retry.
        Schema::createIfMissing('employee_internships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('institution'); // school/university/vocational institution name
            $table->string('program')->nullable(); // study program / field
            $table->string('mentor_name')->nullable();
            $table->string('agreement_number')->nullable(); // internship/PKL agreement or reference number
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('work_location')->nullable();
            $table->boolean('induction_completed')->default(false);
            $table->string('insurance_coverage')->nullable(); // BPJS or other applicable coverage reference
            $table->text('evaluation')->nullable();
            $table->string('completion_status')->default('ongoing'); // ongoing, completed, terminated
            $table->string('certificate_path')->nullable(); // completion certificate attachment
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_internships');
    }
};
