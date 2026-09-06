<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HseEquipmentType;
use App\Models\Module;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Position;
use App\Models\Project;
use App\Models\SafetyEquipment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * v2.53.0 -- the IOMS Sandbox tenant.
 *
 * A real tenant, flagged `is_demo`, seeded with an operation that looks
 * like it has been running for a while: departments, people, projects,
 * permits in flight, equipment on its inspection cycle. An empty
 * workspace is a bad demonstration — a prospect cannot tell what IOMS
 * looks like in use from a page of zeros.
 *
 * WHAT IS FABRICATED AND WHAT IS NOT. The operational records below are
 * invented, and they are labelled as a demonstration everywhere they are
 * shown. What is NOT invented is anything a reader could mistake for a
 * business claim: no customer names, no logos, no headcounts presented as
 * IOMS's own, no testimonials, no certifications, no "trusted by N
 * companies". The demo company is transparently fictional.
 *
 * ISOLATION. This is an ordinary tenant, so TenantScope keeps it apart
 * from every customer exactly as customers are kept apart from each
 * other. Nothing here weakens a boundary for the sake of a demo.
 *
 * ENTITLEMENTS ARE DELIBERATELY PARTIAL. The Sandbox is granted a few
 * workspaces, not all of them, so a prospect sees genuinely locked
 * modules through the SAME entitlement mechanism a real customer would —
 * "not included in your plan" is real here, not a mock-up.
 *
 * Run explicitly:  php artisan db:seed --class=DemoTenantSeeder
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => config('ioms.sandbox.tenant_slug', 'ioms-sandbox')],
            ['name' => 'Nusantara Marine Works (Demo)', 'status' => Tenant::STATUS_ACTIVE, 'is_demo' => true]
        );

        // Re-assert the flag: a tenant that exists but is not flagged is
        // exactly the misconfiguration SandboxController refuses to sign
        // anyone into, and silently leaving it unset would disable the
        // Sandbox for no visible reason.
        $tenant->update(['is_demo' => true, 'status' => Tenant::STATUS_ACTIVE]);

        $previous = app(CurrentTenant::class)->get();
        app(CurrentTenant::class)->set($tenant);

        try {
            $company = $this->company($tenant);
            $this->identity($company);
            $this->entitlements($tenant);
            $this->subscription($tenant);
            [$departments, $positions] = $this->structure($company);
            $employees = $this->employees($company, $departments, $positions);
            $user = $this->demoUser($tenant, $company);
            $projects = $this->projects($company, $user);
            $this->equipment($company);
            $this->permits($company, $user, $employees, $projects);
        } finally {
            app(CurrentTenant::class)->set($previous);
        }

        $this->command?->info('IOMS Sandbox seeded. Sign in route: /sandbox');
    }

    private function company(Tenant $tenant): Company
    {
        return Company::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Nusantara Marine Works'],
            ['code' => 'NMW', 'is_active' => true]
        );
    }

    /** The demo company's own letterhead identity, so generated documents look real. */
    private function identity(Company $company): void
    {
        foreach ([
            'company_name' => 'Nusantara Marine Works',
            'company_legal_name' => 'PT Nusantara Marine Works',
            'company_address' => 'Jl. Bawal Kav. 21, Batu Merah',
            'company_city' => 'Batam',
            'company_province' => 'Kepulauan Riau',
            'company_postal_code' => '29453',
            'company_country' => 'Indonesia',
            'company_phone' => '+62 778 000 000',
            'company_email' => 'ops@nusantaramarine.demo',
            'company_tax_id' => '00.000.000.0-000.000',
            'company_industry' => 'Shipyard & Marine',
        ] as $key => $value) {
            CompanySetting::set($key, $value);
        }
    }

    /**
     * Partial on purpose. The Sandbox demonstrates a connected platform,
     * so it needs more than one domain — but locked modules are part of
     * what it demonstrates, and they have to be locked by the real
     * entitlement mechanism rather than a mock.
     */
    private function entitlements(Tenant $tenant): void
    {
        $granted = ['hse', 'hr', 'project-management'];

        $tenant->workspaces()->sync(Workspace::whereIn('key', $granted)->pluck('id'));
        $tenant->modules()->sync(Module::whereIn('key', ['employees', 'ppe', 'reports'])->pluck('id'));
    }

    private function subscription(Tenant $tenant): void
    {
        $package = Package::where('slug', 'professional')->first() ?? Package::first();

        if (! $package) {
            return;
        }

        Subscription::firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'package_id' => $package->id,
                'type' => Subscription::TYPE_SUBSCRIPTION,
                'status' => Subscription::STATUS_ACTIVE,
                'billing_cycle' => Subscription::CYCLE_YEARLY,
                'starts_at' => now()->subMonths(4),
                'ends_at' => now()->addMonths(8),
                'notes' => 'IOMS Sandbox — demonstration tenant, not a paying customer.',
            ]
        );
    }

    /** @return array{0: array<string,Department>, 1: array<string,Position>} */
    private function structure(Company $company): array
    {
        $departments = [];
        foreach ([
            'HSE' => 'Health, Safety & Environment',
            'PRD' => 'Production',
            'MNT' => 'Maintenance',
            'PRJ' => 'Project Management',
        ] as $code => $name) {
            $departments[$code] = Department::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                ['name' => $name, 'is_active' => true]
            );
        }

        $positions = [];
        foreach ([
            'HSE Officer' => 'HSE',
            'Safety Supervisor' => 'HSE',
            'Foreman' => 'PRD',
            'Welder' => 'PRD',
            'Fitter' => 'PRD',
            'Mechanic' => 'MNT',
            'Project Engineer' => 'PRJ',
        ] as $name => $deptCode) {
            $positions[$name] = Position::firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['department_id' => $departments[$deptCode]->id, 'is_active' => true]
            );
        }

        return [$departments, $positions];
    }

    /** @return array<int,Employee> */
    private function employees(Company $company, array $departments, array $positions): array
    {
        $people = [
            ['NMW-0101', 'Ahmad Fauzi', 'Safety Supervisor', 'HSE'],
            ['NMW-0102', 'Rina Kusuma', 'HSE Officer', 'HSE'],
            ['NMW-0201', 'Bambang Setiawan', 'Foreman', 'PRD'],
            ['NMW-0202', 'Joko Prasetyo', 'Welder', 'PRD'],
            ['NMW-0203', 'Slamet Riyadi', 'Welder', 'PRD'],
            ['NMW-0204', 'Dedi Kurniawan', 'Fitter', 'PRD'],
            ['NMW-0301', 'Agus Salim', 'Mechanic', 'MNT'],
            ['NMW-0401', 'Sari Handayani', 'Project Engineer', 'PRJ'],
        ];

        $created = [];
        foreach ($people as [$employeeId, $name, $position, $deptCode]) {
            $created[] = Employee::firstOrCreate(
                ['company_id' => $company->id, 'employee_id' => $employeeId],
                [
                    'full_name' => $name,
                    'department_id' => $departments[$deptCode]->id,
                    'position_id' => $positions[$position]->id,
                    'status' => 'active',
                ]
            );
        }

        return $created;
    }

    /**
     * The shared demo account.
     *
     * An ordinary tenant user with an ordinary role — every boundary that
     * applies to a customer applies to it. It is given PTW Access because
     * raising a permit is the single interaction a prospect most wants to
     * try; RestrictDemoTenant keeps everything else read-only.
     */
    private function demoUser(Tenant $tenant, Company $company): User
    {
        $email = config('ioms.sandbox.user_email', 'demo@ioms.id');

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => 'IOMS Sandbox',
                // Randomised, and never surfaced. The Sandbox is entered
                // through /sandbox, not through the login form, so this
                // account has no usable password by design.
                'password' => Hash::make(bin2hex(random_bytes(24))),
                'role' => User::ROLE_HSE,
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'is_active' => true,
                'ptw_access' => true,
            ]
        );

        $user->update(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true, 'ptw_access' => true]);

        return $user;
    }

    /** @return array<int,Project> */
    private function projects(Company $company, User $user): array
    {
        $projects = [];

        foreach ([
            ['MV Twin Sister 307 Docking', 'TS-307', 'MV Twin Sister 307', 'Graving Dock 2'],
            ['Yard Expansion Phase 2', 'YE-02', null, 'North Yard'],
        ] as [$name, $code, $vessel, $location]) {
            $projects[] = Project::firstOrCreate(
                ['company_id' => $company->id, 'project_code' => $code],
                [
                    'name' => $name,
                    'vessel_name' => $vessel,
                    'location' => $location,
                    'start_date' => now()->subMonths(2)->toDateString(),
                ]
            );
        }

        return $projects;
    }

    /**
     * Equipment Master and Equipment Register, seeded as the two distinct
     * things they are — types with their own lifecycle profile, and
     * individual units carrying only the dates their type actually
     * tracks. A fire extinguisher expires; a gas detector is calibrated;
     * a blower is serviced. None of them carries all three.
     */
    private function equipment(Company $company): void
    {
        $types = [
            ['Gas Detector', 'GAS-DET', 'GD', ['inspection' => true, 'calibration' => true, 'service' => false, 'expiry' => false], 6],
            ['Fire Extinguisher', 'FIRE-EXT', 'FE', ['inspection' => true, 'calibration' => false, 'service' => false, 'expiry' => true], 6],
            ['Blower / Ventilator', 'BLOWER', 'BL', ['inspection' => true, 'calibration' => false, 'service' => true, 'expiry' => false], 12],
            ['Safety Shower', 'SAFE-SHW', 'SS', ['inspection' => true, 'calibration' => false, 'service' => true, 'expiry' => false], 12],
        ];

        $created = [];
        foreach ($types as $i => [$name, $code, $prefix, $tracks, $interval]) {
            $created[$prefix] = HseEquipmentType::firstOrCreate(
                ['company_id' => $company->id, 'code' => $code],
                [
                    'name' => $name,
                    'code_prefix' => $prefix,
                    'tracks_inspection' => $tracks['inspection'],
                    'tracks_calibration' => $tracks['calibration'],
                    'tracks_service' => $tracks['service'],
                    'tracks_expiry' => $tracks['expiry'],
                    'inspection_interval_months' => $interval,
                    'is_active' => true,
                    'sort_order' => $i,
                ]
            );
        }

        $units = [
            ['GD', 'GD-001', 'MSA Altair 4XR', 'Workshop Store', now()->subDays(20), now()->addMonths(5), null, null, now()->addMonths(2)],
            ['GD', 'GD-002', 'MSA Altair 4XR', 'Graving Dock 2', now()->subDays(80), now()->addDays(10), null, null, now()->subDays(5)],
            ['FE', 'FE-001', 'Servvo 6kg ABC', 'Workshop A', now()->subDays(40), now()->addMonths(4), now()->addMonths(14), null, null],
            ['FE', 'FE-002', 'Servvo 9kg ABC', 'Paint Store', now()->subDays(200), now()->subDays(15), now()->addMonths(3), null, null],
            ['BL', 'BL-001', 'Elektrik 12in', 'Graving Dock 2', now()->subDays(30), now()->addMonths(11), null, now()->addMonths(6), null],
            ['SS', 'SS-001', 'Haws 8300', 'Chemical Store', now()->subDays(60), now()->addMonths(10), null, now()->addMonths(5), null],
        ];

        foreach ($units as [$prefix, $unitCode, $model, $location, $lastInspection, $nextInspection, $expiry, $service, $calibration]) {
            $type = $created[$prefix];

            SafetyEquipment::firstOrCreate(
                ['company_id' => $company->id, 'equipment_code' => $unitCode],
                [
                    'equipment_type_id' => $type->id,
                    'name' => $type->name.' '.$unitCode,
                    // The legacy free-text column is kept in step so any
                    // surface that still reads it shows the same thing.
                    'type' => $type->name,
                    'model' => $model,
                    'location' => $location,
                    'serial_number' => strtoupper($unitCode).'-SN',
                    'commissioned_at' => now()->subYear()->toDateString(),
                    'last_inspection_date' => $lastInspection?->toDateString(),
                    'next_inspection_due' => $nextInspection?->toDateString(),
                    'expiry_date' => $expiry?->toDateString(),
                    'next_service_due' => $service?->toDateString(),
                    'next_calibration_due' => $calibration?->toDateString(),
                    'status' => 'active',
                ]
            );
        }
    }

    /**
     * Permits in a few different states, and deliberately a mix of
     * project-linked and project-less work — the point being that a
     * permit's identity does not depend on Project Management having
     * created anything.
     */
    private function permits(Company $company, User $user, array $employees, array $projects): void
    {
        if (PermitToWork::where('company_id', $company->id)->exists()) {
            return;
        }

        $foreman = collect($employees)->firstWhere('employee_id', 'NMW-0201');

        $permits = [
            [
                'ptw_number' => 'PTW-'.now()->year.'-00001',
                'project_id' => $projects[0]->id ?? null,
                'work_reference' => null,
                'permit_type' => 'hot_work',
                'work_description' => 'Hot work for shell plate replacement at starboard side.',
                'location' => 'Graving Dock 2 — Starboard Hull',
                'status' => PermitToWork::STATUS_ACTIVE,
                'offset' => 0,
            ],
            [
                'ptw_number' => 'PTW-'.now()->year.'-00002',
                'project_id' => null,
                // No Project Master — routine maintenance work that still
                // has a real operational identity.
                'work_reference' => 'Workshop Line 1',
                'permit_type' => 'confined_space',
                'work_description' => 'Tank cleaning and inspection inside ballast tank 3P.',
                'location' => 'Ballast Tank 3P',
                'status' => PermitToWork::STATUS_SUBMITTED,
                'offset' => 1,
            ],
            [
                'ptw_number' => 'PTW-'.now()->year.'-00003',
                'project_id' => $projects[1]->id ?? null,
                'work_reference' => null,
                'permit_type' => 'working_at_height',
                'work_description' => 'Scaffolding erection for new workshop roof structure.',
                'location' => 'North Yard — Workshop B',
                'status' => PermitToWork::STATUS_APPROVED,
                'offset' => 2,
            ],
        ];

        foreach ($permits as $permit) {
            $offset = $permit['offset'];
            unset($permit['offset']);

            PermitToWork::create([
                ...$permit,
                'company_id' => $company->id,
                'requested_by' => $user->id,
                'pic_employee_id' => $foreman?->id,
                'start_datetime' => now()->addDays($offset)->setTime(8, 0),
                'end_datetime' => now()->addDays($offset)->setTime(17, 0),
                'precautions' => 'Fire watch standby, gas test before entry, permit displayed at work location.',
            ]);
        }
    }
}
