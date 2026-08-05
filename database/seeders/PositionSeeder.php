<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    /**
     * Seeds sample positions per (company, department) pair. Runs after
     * CompanySeeder and DepartmentSeeder. Department names are no longer
     * globally unique (both GAJ and Maintenance have "HSE", "Engineering",
     * etc.), so this looks departments up scoped to a company rather than
     * by name alone -- avoids seeding into the wrong company's department.
     *
     * v1.3.1: assigns a default sort_order using a rank keyword match
     * against the spec's example seniority order (General Manager >
     * Manager > Superintendent > Supervisor > Foreman > Leadman > Staff),
     * falling back to seed-list position for anything that doesn't match
     * one of those keywords. Uses firstOrCreate (not updateOrCreate) so
     * re-running this seeder never overwrites a sort_order an admin has
     * since customized via Settings.
     */
    private const RANK_KEYWORDS = [
        'general manager' => 10,
        'manager' => 20,
        'superintendent' => 25,
        'supervisor' => 30,
        'foreman' => 40,
        'leadman' => 50,
        'staff' => 90,
    ];

    public function run(): void
    {
        $positionMap = [
            'Management' => ['General Manager', 'Manager', 'Supervisor'],
            'Engineering' => ['Design Engineer', 'Piping Engineer', 'Draftsman'],
            'HSE' => ['HSE Manager', 'HSE Officer', 'Safety Inspector'],
            'HRGA' => ['HR Officer', 'GA Officer', 'Recruiter'],
            'Mechanical' => ['Mechanical Technician', 'Mechanical Supervisor'],
            'Electrical' => ['Electrician', 'Electrical Supervisor'],
            'Finance & Accounting' => ['Accountant', 'Finance Officer'],
            'Docking & Fasgal' => ['Docking Supervisor', 'Fasgal Technician'],
            'Blasting & Painting' => ['Blaster', 'Painter', 'Coating Inspector'],
            'Warehouse Logistics' => ['Warehouse Staff', 'Logistics Officer'],
            'Warehouse' => ['Warehouse Staff', 'Logistics Officer'],
            'Heavy Equipment' => ['Heavy Equipment Operator', 'Heavy Equipment Technician'],
            'Construction' => ['Fitter', 'Rigger', 'Welder', 'Foreman'],
            'Workshop Bubut' => ['Machinist'],
            'Security' => ['Security Officer'],
            'Driver Truck' => ['Truck Driver'],
            'Interior' => ['Interior Technician'],
            'Others' => ['Staff'],
        ];

        foreach (Company::all() as $company) {
            foreach ($positionMap as $deptName => $positions) {
                $department = Department::where('company_id', $company->id)->where('name', $deptName)->first();
                if (! $department) {
                    continue; // this company doesn't have this department, skip
                }

                foreach ($positions as $index => $positionName) {
                    // v1.10.5 fix: company_id was missing from both the
                    // match and create arrays -- positions.company_id has
                    // been NOT NULL with no default since the Multi-Company
                    // migration (2026_08_11_100035), so this insert failed
                    // on any fresh/strict-mode database. $company is
                    // already in scope from the outer loop; matching
                    // DepartmentSeeder's own (company_id, name) pattern.
                    Position::firstOrCreate(
                        ['name' => $positionName, 'department_id' => $department->id, 'company_id' => $company->id],
                        ['sort_order' => $this->rankFor($positionName, $index)]
                    );
                }
            }
        }
    }

    private function rankFor(string $positionName, int $fallbackIndex): int
    {
        $lower = strtolower($positionName);

        foreach (self::RANK_KEYWORDS as $keyword => $rank) {
            if (str_contains($lower, $keyword)) {
                return $rank;
            }
        }

        return 60 + ($fallbackIndex * 5); // unranked titles: keep seed-list order, slotted after Foreman
    }
}
