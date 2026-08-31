<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.17.0 (PTW Field Workflow Foundation, Part 8). PIC / Supervisor
 * Lapangan -- deliberately a SEPARATE person from `requested_by`
 * (Requester = the authenticated user who submitted the PTW; PIC = the
 * person responsible for the work in the field, who may not be the
 * submitter at all -- a Foreman might submit on behalf of the crew's
 * actual field supervisor).
 *
 * References `employees.id`, not `users.id` -- per this phase's own
 * "PIC should be selected from existing Employee/User data... do NOT
 * use free-text names" instruction, and because the PIC is not
 * necessarily someone with an IOMS login at all (an Employee record can
 * exist without a User account, confirmed by audit -- `Employee` has no
 * `user_id`/User relation in this codebase). Nullable + `nullOnDelete`:
 * PIC is explicitly optional per the product direction ("Keep this
 * optional unless the current business rules require it"), and an
 * Employee being later removed must never cascade-delete a PTW record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permits_to_work', function (Blueprint $table) {
            $table->foreignId('pic_employee_id')->nullable()->after('requested_by')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('permits_to_work', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pic_employee_id');
        });
    }
};
