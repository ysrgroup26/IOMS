<?php

use App\Models\PpeType;
use Illuminate\Database\Migrations\Migration;

/**
 * v2.126.0 -- A WORK UNIFORM IS NOT A COVERALL.
 *
 * The shared catalogue's only work-clothing entry was "Coverall", because
 * ADR 060 judged a uniform to be a per-customer item added in PPE Master.
 * The mechanism for that is real and unchanged -- PPE Master exposes
 * `compliance_role` and a tenant may add a type whenever they like -- but
 * what it produced in practice was an HSE officer issuing a baju kerja
 * being offered one option, and it the wrong garment.
 *
 * A coverall is worn OVER work clothing and takes the wear; a uniform is
 * replaced less often. They are different equipment, and the owner's
 * instruction is explicit that the two must not be conflated.
 *
 * So the built-in joins the catalogue the same way every other built-in is
 * there: `company_id` null, declaring ROLE_WORK_CLOTHING. ADR 060's
 * completeness rule is untouched -- work clothing is satisfied by Work
 * Uniform OR Coverall, which is what it has always said; this is the first
 * release in which the shipped catalogue actually offers both.
 *
 * NOTHING IS BACKFILLED AND NOTHING IS REWRITTEN. No issuance is created,
 * no existing coverall record is reinterpreted, and a tenant that already
 * added its own "Work Uniform" keeps it: the built-in is matched by name
 * among the SHARED rows only, so a company-owned row of the same name is a
 * different row and stays theirs (ADR 058).
 */
return new class extends Migration
{
    public function up(): void
    {
        PpeType::withoutGlobalScopes()->updateOrCreate(
            ['company_id' => null, 'name' => 'Work Uniform'],
            [
                'replacement_interval_months' => 12,
                'compliance_role' => PpeType::ROLE_WORK_CLOTHING,
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        /*
         * Deactivated, not deleted. `employee_ppes` points at this row by
         * id, and by the time anybody rolls back a tenant may have issued
         * uniforms against it -- deleting it would strand those records,
         * which is the exact failure ADR 058 exists to prevent.
         */
        PpeType::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('name', 'Work Uniform')
            ->update(['is_active' => false]);
    }
};
