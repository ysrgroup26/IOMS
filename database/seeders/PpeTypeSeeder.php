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
        $types = [
            ['name' => 'Safety Helmet', 'replacement_interval_months' => 12],
            ['name' => 'Safety Shoes', 'replacement_interval_months' => 12],
            ['name' => 'Coverall', 'replacement_interval_months' => 6],
            ['name' => 'Safety Glasses', 'replacement_interval_months' => 3],
            ['name' => 'Headlamp', 'replacement_interval_months' => null], // request-based
            ['name' => 'Harness', 'replacement_interval_months' => null], // request-based
        ];

        foreach ($types as $type) {
            PpeType::updateOrCreate(['name' => $type['name']], $type + ['is_active' => true]);
        }
    }
}
