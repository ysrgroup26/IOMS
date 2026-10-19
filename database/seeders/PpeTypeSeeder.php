<?php

namespace Database\Seeders;

use App\Models\PpeType;
use Illuminate\Database\Seeder;

class PpeTypeSeeder extends Seeder
{
    /**
     * Seeds the example PPE types from the spec. These are ordinary rows,
     * not hardcoded logic -- Super Admin can add, edit, deactivate, or
     * change intervals for any of these (or add new ones) via the PPE
     * Master page with zero code changes.
     */
    public function run(): void
    {
        /*
         * v2.121.0 -- THE SHIPPED CATALOGUE DECLARES ITS OWN ROLES.
         *
         * `compliance_role` says which completeness requirement a type
         * satisfies (see PpeComplianceService). The migration that added
         * the column back-fills these for installations that already had
         * the rows; THIS is what gives them to a fresh database, where
         * seeders run after migrations and the rows did not exist yet.
         * Without it a new installation would ship a catalogue that can
         * never make anybody compliant.
         *
         * `null` is additional equipment -- recorded, never required.
         *
         * v2.126.0 -- WORK UNIFORM IS A BUILT-IN NOW, and that is an
         * owner decision overriding this comment's previous paragraph.
         *
         * It used to say a uniform is a per-customer item, added once in
         * PPE Master. The mechanism for that is real and still works --
         * PPE Master exposes `compliance_role`, and a tenant can add one
         * any time. What it produced in practice is a catalogue whose ONLY
         * work-clothing entry is "Coverall", so an HSE officer issuing a
         * baju kerja had one option and it was the wrong garment. A
         * coverall and a work uniform are different equipment with
         * different replacement cycles, and the owner's instruction is
         * explicit that the two must not be conflated.
         *
         * So both ship, both declare ROLE_WORK_CLOTHING, and ADR 060's
         * rule is unchanged: work clothing is satisfied by EITHER. Two
         * types sharing a role was always the designed case -- this is the
         * first time the shipped catalogue actually exercises it.
         */
        $types = [
            ['name' => 'Safety Helmet', 'replacement_interval_months' => 12, 'compliance_role' => PpeType::ROLE_SAFETY_HELMET],
            ['name' => 'Safety Shoes', 'replacement_interval_months' => 12, 'compliance_role' => PpeType::ROLE_SAFETY_SHOES],
            ['name' => 'Coverall', 'replacement_interval_months' => 6, 'compliance_role' => PpeType::ROLE_WORK_CLOTHING],
            // A uniform is replaced less often than a coverall, which is
            // worn over it and takes the wear. Both satisfy work clothing.
            ['name' => 'Work Uniform', 'replacement_interval_months' => 12, 'compliance_role' => PpeType::ROLE_WORK_CLOTHING],
            ['name' => 'Safety Glasses', 'replacement_interval_months' => 3, 'compliance_role' => null],
            ['name' => 'Headlamp', 'replacement_interval_months' => null, 'compliance_role' => null], // request-based
            ['name' => 'Harness', 'replacement_interval_months' => null, 'compliance_role' => null], // request-based
        ];

        foreach ($types as $type) {
            PpeType::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => null, 'name' => $type['name']],
                $type + ['is_active' => true]
            );
        }
    }
}
