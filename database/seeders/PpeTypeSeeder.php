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
         * "Work Uniform" is deliberately absent: a uniform is a
         * per-customer item, added once in PPE Master and marked as work
         * clothing there (ADR 058 on what a built-in is for).
         */
        $types = [
            ['name' => 'Safety Helmet', 'replacement_interval_months' => 12, 'compliance_role' => PpeType::ROLE_SAFETY_HELMET],
            ['name' => 'Safety Shoes', 'replacement_interval_months' => 12, 'compliance_role' => PpeType::ROLE_SAFETY_SHOES],
            ['name' => 'Coverall', 'replacement_interval_months' => 6, 'compliance_role' => PpeType::ROLE_WORK_CLOTHING],
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
