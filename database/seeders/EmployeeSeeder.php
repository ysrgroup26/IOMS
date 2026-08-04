<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\KpiCategory;
use App\Models\KpiRecord;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class EmployeeSeeder extends Seeder
{
    /**
     * Seeds demo employees + a few months of KPI history so the Dashboard,
     * Reports, and charts are populated on first login. Safe to remove
     * for a production dataset -- run `php artisan db:seed --class=DemoResetSeeder`
     * is not required; simply skip this seeder in DatabaseSeeder for prod.
     */
    public function run(): void
    {
        if (Employee::count() > 0) {
            return; // avoid duplicating demo data on re-seed
        }

        $employees = Employee::factory()->count(60)->create();
        $categories = KpiCategory::all();
        $admin = User::where('role', User::ROLE_SUPER_ADMIN)->first();

        foreach (range(0, 5) as $monthsAgo) {
            $date = Carbon::now()->subMonths($monthsAgo);

            foreach ($employees as $employee) {
                // Most employees attend TBM most months (realistic baseline).
                if (fake()->boolean(70)) {
                    $this->makeRecord($employee, $categories->firstWhere('code', KpiCategory::TBM), $date, $admin);
                }
                if (fake()->boolean(20)) {
                    $this->makeRecord($employee, $categories->firstWhere('code', KpiCategory::DRILL), $date, $admin);
                }
                if (fake()->boolean(15)) {
                    $this->makeRecord($employee, $categories->firstWhere('code', KpiCategory::CAMPAIGN), $date, $admin);
                }
                if (fake()->boolean(10)) {
                    $this->makeRecord($employee, $categories->firstWhere('code', KpiCategory::BBS_NEARMISS), $date, $admin);
                }
                if (fake()->boolean(4)) {
                    $this->makeRecord($employee, $categories->firstWhere('code', KpiCategory::PPE_VIOLATION), $date, $admin);
                }
                if (fake()->boolean(2)) {
                    $this->makeRecord($employee, $categories->firstWhere('code', KpiCategory::FAC), $date, $admin);
                }
                if (fake()->boolean(1)) {
                    $this->makeRecord($employee, $categories->firstWhere('code', KpiCategory::LTI), $date, $admin);
                }
            }
        }
    }

    private function makeRecord(Employee $employee, KpiCategory $category, Carbon $date, ?User $creator): void
    {
        KpiRecord::create([
            'employee_id' => $employee->id,
            'department_id' => $employee->department_id,
            'kpi_category_id' => $category->id,
            'record_date' => $date->copy()->day(fake()->numberBetween(1, 27)),
            'quantity' => 1,
            'remarks' => null,
            'created_by' => $creator?->id ?? 1,
        ]);
    }
}
