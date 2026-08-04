<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\DailyReport;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\KpiRecord;
use App\Models\PpeType;
use App\Models\Project;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\DailyReportPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\EmployeePpePolicy;
use App\Policies\KpiRecordPolicy;
use App\Policies\PpeTypePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Employee::class => EmployeePolicy::class,
        KpiRecord::class => KpiRecordPolicy::class,
        User::class => UserPolicy::class,
        Company::class => CompanyPolicy::class,
        Project::class => ProjectPolicy::class,
        PpeType::class => PpeTypePolicy::class,
        EmployeePpe::class => EmployeePpePolicy::class,
        DailyReport::class => DailyReportPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
