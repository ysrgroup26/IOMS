<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\CompetencyType;
use App\Models\DailyReport;
use App\Models\Employee;
use App\Models\EmployeePpe;
use App\Models\HazardCategory;
use App\Models\KpiRecord;
use App\Models\ManHourLog;
use App\Models\PpeType;
use App\Models\Project;
use App\Models\RosterPattern;
use App\Models\Shift;
use App\Models\User;
use App\Policies\CompanyPolicy;
use App\Policies\CompetencyTypePolicy;
use App\Policies\DailyReportPolicy;
use App\Policies\EmployeePolicy;
use App\Policies\EmployeePpePolicy;
use App\Policies\HazardCategoryPolicy;
use App\Policies\KpiRecordPolicy;
use App\Policies\ManHourLogPolicy;
use App\Policies\PpeTypePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RosterPatternPolicy;
use App\Policies\ShiftPolicy;
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
        CompetencyType::class => CompetencyTypePolicy::class,
        Shift::class => ShiftPolicy::class,
        RosterPattern::class => RosterPatternPolicy::class,
        HazardCategory::class => HazardCategoryPolicy::class,
        ManHourLog::class => ManHourLogPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
