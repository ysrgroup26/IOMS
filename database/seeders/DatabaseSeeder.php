<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            CompanySeeder::class,
            DepartmentSeeder::class,
            PositionSeeder::class,
            KpiCategorySeeder::class,
            PpeTypeSeeder::class,
            UserSeeder::class,
            CompanySettingSeeder::class,
            EmployeeSeeder::class, // demo data; safe to skip in production via --class flag usage
        ]);
    }
}
