<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.69.0 -- EMPLOYEE CASES. The HR record of a concern raised about a
 * person, from the moment it is raised to the moment it is concluded.
 *
 * WHY A CASE AND NOT A FLAG ON `employees`. The obvious shape -- a
 * `disciplinary_status` column, or a colour on the employee row -- cannot
 * answer any of the questions this record exists to answer: what
 * happened, when, who decided, what was issued, is it still in force, and
 * has it happened before. A stored status is also the one thing that
 * silently goes stale: an SP lapses on a date, and a column does not know
 * that. Current standing is therefore DERIVED from the actions below (see
 * Employee::currentDisciplinaryStanding()), never stored -- the same rule
 * `Employee::profile_status` and `PurchaseOrderItem::delivered_quantity`
 * already follow in this codebase.
 *
 * WHY "CASE" AND NOT "DISCIPLINARY RECORD". Not every concern raised is
 * misconduct, and naming the container after the worst outcome prejudges
 * it. A case may be dismissed with no action at all, and that outcome has
 * to be recordable without the record itself calling the person
 * disciplined. The disciplinary ACTION is the child row, and only exists
 * when one was actually issued.
 *
 * SHAPE. This mirrors the case-and-actions pattern already established by
 * Incident -> CorrectiveAction and Ncr, rather than inventing a new one.
 * It deliberately does NOT reuse `corrective_actions` itself: that table
 * models an assigned remedial task (assignee, due date, evidence,
 * verification) whereas a disciplinary action models an issued sanction
 * with a validity window and an acknowledgement. Forcing one table to be
 * both would have made half its columns meaningless in either direction.
 *
 * CONFIDENTIALITY. These rows are company-owned (`BelongsToCompany`) like
 * everything else, and additionally gated in the controller to HR and
 * Company Admin only -- see User::canManageEmployeeCases(). An employee's
 * own line manager does not get to read them by virtue of being a manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number');
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->enum('category', [
                'conduct', 'attendance', 'performance', 'safety', 'policy', 'other',
            ])->default('conduct');

            $table->enum('severity', ['low', 'medium', 'high'])->default('low')->index();

            $table->string('title');
            $table->text('details')->nullable();

            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reported_at');

            // The HR person answerable for moving this case along. Without
            // one, an open case belongs to everybody and therefore nobody.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('status', [
                'open', 'under_review', 'action_issued', 'closed', 'dismissed',
            ])->default('open');

            // Recorded at conclusion, and only then -- a case under review
            // has no outcome yet, which is different from having none.
            $table->enum('outcome', ['substantiated', 'unsubstantiated', 'withdrawn'])->nullable();

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('closure_note')->nullable();

            $table->timestamps();
            // An HR case is never hard-deleted: it is evidence of a
            // decision about a person, and may be needed long after the
            // fact.
            $table->softDeletes();

            // Numbers are per Operating Unit, matching the uniqueness rule
            // v2.51.0 established for every other document number.
            $table->unique(['company_id', 'case_number']);

            // The two reads this table actually serves: one employee's
            // history, and the open caseload.
            $table->index(['employee_id', 'reported_at']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_cases');
    }
};
