<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\KpiCategory;
use App\Models\PpeType;
use App\Models\Tenant;
use App\Models\User;
use App\Rules\InCurrentTenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * v2.40.0 -- request-INPUT isolation.
 *
 * Distinct from the route-model-binding guards (TenantIsolationTest): a
 * record can be perfectly guarded on read and still be reachable as a
 * submitted foreign key. Two such gaps survived the v2.37.0 sweep because
 * their sibling fields had been converted and they had not:
 *
 *   kpi_category_id  -- kpi_categories IS tenant-owned (company_id, added
 *                       2026_07_20_100017). A raw exists: let a KPI record
 *                       be filed against another tenant's taxonomy.
 *   employee_ppe_id  -- worse, a cross-tenant WRITE: storeReplacementRequest()
 *                       flipped the referenced row to replacement_requested
 *                       and copied its owner's company_id onto the new
 *                       request. employee_ppe has no company_id of its own,
 *                       so it needs one-hop resolution through employees.
 */
class CrossTenantInputValidationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $slug): array
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
        $company = Company::withoutGlobalScopes()->create(['name' => strtoupper($slug), 'tenant_id' => $tenant->id]);
        $department = Department::create(['name' => 'Produksi', 'company_id' => $company->id]);

        $employee = Employee::create([
            'employee_id' => strtoupper($slug).'-1', 'full_name' => 'Worker',
            'company_id' => $company->id, 'department_id' => $department->id, 'status' => 'active',
        ]);

        $category = KpiCategory::create([
            'company_id' => $company->id, 'code' => strtoupper($slug),
            'name' => ucfirst($slug).' Category', 'short_label' => strtoupper($slug),
        ]);

        $user = User::create([
            'name' => 'Admin', 'email' => "admin@{$slug}.test", 'password' => bcrypt('x'),
            'role' => 'super_admin', 'tenant_id' => $tenant->id, 'is_active' => true,
        ]);

        return compact('tenant', 'company', 'employee', 'category', 'user');
    }

    private function assertRuleRejects(InCurrentTenant $rule, mixed $value, string $message): void
    {
        $v = Validator::make(['field' => $value], ['field' => [$rule]]);
        $this->assertTrue($v->fails(), $message);
    }

    private function assertRuleAccepts(InCurrentTenant $rule, mixed $value, string $message): void
    {
        $v = Validator::make(['field' => $value], ['field' => [$rule]]);
        $this->assertFalse($v->fails(), $message);
    }

    public function test_a_foreign_kpi_category_is_rejected(): void
    {
        $a = $this->makeTenant('acme');
        $b = $this->makeTenant('borneo');

        app(CurrentTenant::class)->set($a['tenant']);

        $this->assertRuleAccepts(new InCurrentTenant('kpi_categories'), $a['category']->id, "Own category must be accepted.");
        $this->assertRuleRejects(new InCurrentTenant('kpi_categories'), $b['category']->id, "Another tenant's KPI category was accepted.");
    }

    public function test_a_foreign_employee_ppe_row_is_rejected_through_its_employee(): void
    {
        $a = $this->makeTenant('acme');
        $b = $this->makeTenant('borneo');

        $ppeType = PpeType::create(['name' => 'Helmet', 'is_active' => true]);

        $ppeA = EmployeePpe::create([
            'employee_id' => $a['employee']->id, 'ppe_type_id' => $ppeType->id,
            'issued_date' => now()->toDateString(), 'status' => 'issued', 'issued_by' => $a['user']->id,
        ]);
        $ppeB = EmployeePpe::create([
            'employee_id' => $b['employee']->id, 'ppe_type_id' => $ppeType->id,
            'issued_date' => now()->toDateString(), 'status' => 'issued', 'issued_by' => $b['user']->id,
        ]);

        app(CurrentTenant::class)->set($a['tenant']);

        $rule = fn () => new InCurrentTenant('employee_ppe', 'company_id', ['employee_id', 'employees']);

        $this->assertRuleAccepts($rule(), $ppeA->id, 'Own PPE assignment must be accepted.');
        $this->assertRuleRejects($rule(), $ppeB->id, "Another tenant's PPE assignment was accepted -- this is the cross-tenant write vector.");
    }

    /** A dangling foreign key must fail rather than resolve to a null owner that slips through. */
    public function test_one_hop_rule_rejects_a_missing_row(): void
    {
        $a = $this->makeTenant('acme');
        app(CurrentTenant::class)->set($a['tenant']);

        $this->assertRuleRejects(
            new InCurrentTenant('employee_ppe', 'company_id', ['employee_id', 'employees']),
            999999,
            'A non-existent employee_ppe id must be rejected.'
        );
    }
}
