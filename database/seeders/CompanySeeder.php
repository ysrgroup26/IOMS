<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::updateOrCreate(['name' => 'GAJ'], ['code' => 'GAJ', 'is_active' => true]);
        Company::updateOrCreate(['name' => 'Maintenance'], ['code' => 'MTC', 'is_active' => true]);
    }
}
