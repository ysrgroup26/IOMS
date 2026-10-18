<?php

use App\Models\PpeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v2.121.0 -- WHICH REQUIREMENT A PPE TYPE SATISFIES, SAID EXPLICITLY.
     *
     * The approved completeness rule is: a worker is compliant when they
     * have work clothing (Work Uniform OR Coverall -- either satisfies the
     * group), plus Safety Shoes, plus Safety Helmet.
     *
     * Expressing that needed something `ppe_types` did not have. Until now
     * a type was identified by its DISPLAY NAME alone, and ADR 050
     * deliberately lets a customer rename one through an override -- so a
     * rule matching the literal string "Safety Shoes" would stop
     * recognising a tenant who renamed theirs "Sepatu Safety", and would
     * report that tenant's whole workforce as non-compliant. A safety
     * status that silently depends on spelling is worse than no status.
     *
     * So the type declares its ROLE, and the rule reads the role:
     *
     *   work_clothing  -- Work Uniform, Coverall, or whatever this customer
     *                     calls the thing people wear. SEVERAL types may
     *                     carry it; any one of them satisfies the group.
     *   safety_shoes   -- the footwear requirement.
     *   safety_helmet  -- the head protection requirement.
     *   null           -- additional equipment (Safety Glasses, Harness,
     *                     Headlamp). Recorded, never required.
     *
     * The shipped built-ins are given their roles here. "Work Uniform" is
     * NOT created: it is not in the shipped catalogue, and inventing a type
     * for every tenant in a migration is exactly what ADR 058 says a
     * built-in must not be. A customer who issues uniforms adds the type
     * once in PPE Master and marks it as work clothing -- the same path
     * ADR 050 established, and the reason this column is editable rather
     * than hardcoded.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ppe_types')) {
            return;
        }

        if (! Schema::hasColumn('ppe_types', 'compliance_role')) {
            Schema::table('ppe_types', function (Blueprint $table) {
                $table->string('compliance_role', 30)->nullable()->after('name')->index();
            });
        }

        /*
         * Only the SHARED built-ins are stamped, and only where they are
         * still unset. A company-owned type -- including an override of one
         * of these -- is the customer's to classify, and an override with no
         * role of its own inherits its template's (see
         * PpeType::effectiveComplianceRole()), so nothing has to be
         * back-filled per tenant.
         */
        $roles = [
            'Coverall' => PpeType::ROLE_WORK_CLOTHING,
            'Safety Shoes' => PpeType::ROLE_SAFETY_SHOES,
            'Safety Helmet' => PpeType::ROLE_SAFETY_HELMET,
        ];

        foreach ($roles as $name => $role) {
            PpeType::withoutGlobalScopes()
                ->whereNull('company_id')
                ->whereNull('compliance_role')
                ->where('name', $name)
                ->update(['compliance_role' => $role]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ppe_types') || ! Schema::hasColumn('ppe_types', 'compliance_role')) {
            return;
        }

        Schema::table('ppe_types', function (Blueprint $table) {
            $table->dropIndex(['compliance_role']);
            $table->dropColumn('compliance_role');
        });
    }
};
