<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Company-aware department seeding. Matches on (company_id, name) so
     * re-running this seeder never creates duplicates and never touches
     * departments belonging to a different company.
     *
     * v1.3.1: also seeds a sensible default `sort_order` (list position,
     * spaced by 10 so gaps exist for future manual reordering) matching
     * the example order in the spec. This is only ever a starting point
     * -- Super Admin/HSE can freely re-order via Settings; nothing here
     * is hardcoded logic, just seed data.
     */
    public function run(): void
    {
        $gaj = Company::where('name', 'GAJ')->first();
        $maintenance = Company::where('name', 'Maintenance')->first();

        $gajDepartments = [
            'Management', 'Finance & Accounting', 'Engineering', 'HSE', 'HRGA',
            'Construction', 'Mechanical', 'Electrical',
            'Docking & Fasgal', 'Blasting & Painting', 'Warehouse Logistics',
            'Heavy Equipment', 'Workshop Bubut', 'Security', 'Driver Truck', 'Others',
        ];

        $maintenanceDepartments = [
            'Management', 'Engineering', 'HSE', 'HRGA',
            'Mechanical', 'Electrical', 'Interior', 'Warehouse', 'Others',
        ];

        // firstOrCreate (not updateOrCreate): only sets sort_order the FIRST
        // time a department is seeded. If Super Admin has since customized
        // the order via Settings, re-running this seeder must never clobber
        // that -- so an already-existing row is left completely untouched.
        if ($gaj) {
            foreach ($gajDepartments as $index => $name) {
                Department::firstOrCreate(
                    ['company_id' => $gaj->id, 'name' => $name],
                    ['sort_order' => ($index + 1) * 10]
                );
            }
        }

        if ($maintenance) {
            foreach ($maintenanceDepartments as $index => $name) {
                Department::firstOrCreate(
                    ['company_id' => $maintenance->id, 'name' => $name],
                    ['sort_order' => ($index + 1) * 10]
                );
            }
        }
    }
}
