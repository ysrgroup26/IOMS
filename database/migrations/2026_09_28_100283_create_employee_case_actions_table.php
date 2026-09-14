<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.69.0 -- WHAT WAS ACTUALLY ISSUED, AND UNTIL WHEN.
 *
 * One case can produce more than one action over time (counselling first,
 * then a written warning when the same thing recurs), and an employee's
 * standing is the sum of the actions still in force across ALL their
 * cases. That is why this is a table and not three columns on
 * `employee_cases`.
 *
 * `effective_until` IS THE POINT OF THIS TABLE. A Surat Peringatan is
 * valid for a fixed period -- six months is the usual term -- and after it
 * lapses the employee is back to a clean standing, which is what decides
 * whether the next incident escalates. A stored "current level" column
 * would be correct on the day it was written and wrong every day after,
 * with nothing to signal the change. Storing the WINDOW and deriving the
 * standing from it means the record ages correctly on its own, with no
 * scheduled job and nothing to keep in sync. Nullable because not every
 * action expires: termination does not.
 *
 * `acknowledged_at` records that the employee was actually served the
 * letter, which is a different fact from it having been issued, and the
 * one an employment dispute turns on.
 *
 * OWNERSHIP. No `company_id` of its own: it is owned through its case via
 * `BelongsToCompanyThrough`, the pattern v2.63.0 built for exactly this
 * (a table one join away from its owner). The parent's scope decides what
 * is visible, so the rule is stated once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_case_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_case_id')->constrained()->cascadeOnDelete();

            // Ordered least to most severe. `sp1`/`sp2`/`sp3` keep the
            // established local term the way PTW, HIRADC, JSA and LOTO do
            // elsewhere in IOMS -- translating "Surat Peringatan" into
            // "Warning Letter Level 1" would stop it matching the document
            // the company actually issues.
            $table->enum('type', [
                'counselling',
                'verbal_warning',
                'written_warning',
                'sp1',
                'sp2',
                'sp3',
                'suspension',
                'demotion',
                'termination',
            ]);

            $table->date('issued_at');

            // Null means "does not lapse" (termination), not "unknown".
            $table->date('effective_until')->nullable();

            // The company's own letter reference, when one exists on paper.
            $table->string('reference_number')->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();

            // "Which actions are still in force" is the query this table
            // exists to answer, and it runs on every employee profile.
            $table->index(['employee_case_id', 'issued_at']);
            $table->index('effective_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_case_actions');
    }
};
