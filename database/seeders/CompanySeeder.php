<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    /**
     * Milestone 2: tenant_id must be set explicitly on create -- a global
     * scope filters which rows a query SEES, it does not fill in
     * attributes on insert. DatabaseSeeder already resolved and bound the
     * current tenant before this seeder runs (see its own doc comment).
     */
    public function run(): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        Company::updateOrCreate(['name' => 'GAJ'], ['tenant_id' => $tenantId, 'code' => 'GAJ', 'is_active' => true]);
        Company::updateOrCreate(['name' => 'Maintenance'], ['tenant_id' => $tenantId, 'code' => 'MTC', 'is_active' => true]);
    }
}
