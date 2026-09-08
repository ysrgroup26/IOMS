<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\HseEquipmentType;
use App\Models\Item;
use App\Models\MaintenanceRequest;
use App\Models\MaterialRequest;
use App\Models\Module;
use App\Models\Ncr;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Position;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\SafetyEquipment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Models\WorkOrder;
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
            // v2.54.0 -- the operational chain, seeded in dependency order
            // so each record points at the one that actually caused it.
            $vendors = $this->vendors($company);
            $items = $this->warehouseAndItems($company, $user);
            $this->procurement($company, $user, $vendors, $projects, $departments, $items);
            $assets = $this->assets($company, $vendors, $employees);
            $this->maintenance($company, $user, $assets, $employees);
            $this->quality($company, $user);
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
    /**
     * v2.54.0 -- BROADENED, BUT STILL DELIBERATELY PARTIAL.
     *
     * v2.53.0 granted three workspaces. That made the entitlement
     * mechanism visibly real, which was the point, but it also meant the
     * Sandbox could not show the one thing the product is now sold on: a
     * request raised in one department becoming a requisition, an order,
     * a delivery and a work order in others. A demo of a connected
     * platform that cannot show the connection is a poor demo.
     *
     * So the operational chain is granted and seeded end to end, and
     * Logistics / PPIC and Finance stay locked — enough that a prospect
     * still meets a genuine "not included in your plan" boundary,
     * enforced by the same entitlement layer a customer would meet, and
     * not by anything invented for the Sandbox.
     */
    private function entitlements(Tenant $tenant): void
    {
        $granted = [
            'hse', 'hr', 'project-management',
            'warehouse', 'procurement', 'asset-management', 'maintenance', 'quality-control',
            'reports',
        ];

        $tenant->workspaces()->sync(Workspace::whereIn('key', $granted)->pluck('id'));
        $tenant->modules()->sync(
            Module::whereIn('key', ['employees', 'ppe', 'reports', 'material-request'])->pluck('id')
        );
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
        $email = config('ioms.sandbox.user_email', 'demo@iomsuite.com');

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

    /* ================================================================
     * v2.54.0 -- THE OPERATIONAL CHAIN
     *
     * Every method below is idempotent on a natural key, like the ones
     * above, so re-running the seeder on an existing Sandbox repairs it
     * rather than duplicating it.
     *
     * The records are deliberately CONNECTED, not merely present: the
     * material request names the project the permits are raised against,
     * the requisition cites that request, the purchase order cites that
     * requisition and names a vendor that exists, the asset was bought on
     * that order, the maintenance request reports a fault on that asset,
     * and the work order acts on that request. That chain IS the product
     * story; a page of unrelated rows would demonstrate nothing.
     * ================================================================ */

    private function vendors(Company $company): array
    {
        $rows = [
            ['NMW-VND-001', 'PT Baja Sentosa Nusantara', 'PT Baja Sentosa Nusantara', 'Steel plate, profiles, welding consumables', 'Surabaya'],
            ['NMW-VND-002', 'CV Anugerah Teknik Marine', 'CV Anugerah Teknik Marine', 'Marine pumps, valves, spare parts', 'Semarang'],
            ['NMW-VND-003', 'PT Sinar Alat Keselamatan', 'PT Sinar Alat Keselamatan', 'PPE, gas detection, safety equipment', 'Jakarta'],
        ];

        $vendors = [];
        foreach ($rows as [$code, $name, $legal, $capability, $city]) {
            $vendors[] = Vendor::firstOrCreate(
                ['company_id' => $company->id, 'vendor_code' => $code],
                [
                    'name' => $name,
                    'legal_entity_name' => $legal,
                    'capability' => $capability,
                    'city' => $city,
                    'country' => 'Indonesia',
                    'is_active' => true,
                    'qualification_status' => 'qualified',
                    'qualified_until' => now()->addMonths(9)->toDateString(),
                ]
            );
        }

        return $vendors;
    }

    private function warehouseAndItems(Company $company, User $user): array
    {
        Warehouse::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'WH-MAIN'],
            ['name' => 'Main Yard Store', 'location' => 'North Yard — Building A', 'pic_id' => $user->id, 'status' => 'active']
        );

        Warehouse::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'WH-DOCK'],
            ['name' => 'Dockside Store', 'location' => 'Graving Dock 2', 'pic_id' => $user->id, 'status' => 'active']
        );

        $rows = [
            ['ITM-0001', 'Welding Electrode E7018 3.2mm', 'Consumable', 'box', 20, 200, 'Lincoln'],
            ['ITM-0002', 'Steel Plate A36 10mm 1220x2440', 'Raw Material', 'sheet', 10, 80, null],
            ['ITM-0003', 'Marine Paint Anti-Fouling 20L', 'Consumable', 'pail', 8, 60, 'Jotun'],
            ['ITM-0004', 'Gas Detector Calibration Kit', 'Spare Part', 'set', 2, 10, 'MSA'],
            ['ITM-0005', 'Safety Harness Full Body', 'PPE', 'pcs', 15, 120, '3M'],
            ['ITM-0006', 'Hydraulic Hose 1/2in 3m', 'Spare Part', 'pcs', 6, 40, null],
        ];

        $items = [];
        foreach ($rows as [$code, $name, $category, $unit, $min, $max, $brand]) {
            $items[] = Item::firstOrCreate(
                ['company_id' => $company->id, 'item_code' => $code],
                [
                    'name' => $name, 'category' => $category, 'unit' => $unit,
                    'min_stock' => $min, 'max_stock' => $max, 'brand' => $brand, 'is_active' => true,
                ]
            );
        }

        return $items;
    }

    /**
     * Material Request -> Purchase Requisition -> Purchase Order, each
     * pointing at the one before it. The three sit at different stages on
     * purpose, so the Sandbox shows a pipeline in motion rather than three
     * finished documents.
     */
    private function procurement(Company $company, User $user, array $vendors, array $projects, array $departments, array $items): void
    {
        if (PurchaseOrder::where('company_id', $company->id)->exists()) {
            return;
        }

        $department = $departments['PRD'] ?? null;

        $materialRequest = MaterialRequest::firstOrCreate(
            ['company_id' => $company->id, 'request_number' => 'MR-'.now()->year.'-00001'],
            [
                'request_date' => now()->subDays(12)->toDateString(),
                'project_id' => $projects[0]->id ?? null,
                'department_id' => $department?->id,
                'requested_by' => $user->id,
                'status' => 'approved',
                'notes' => 'Consumables for shell plate replacement, Graving Dock 2.',
            ]
        );

        $requisition = PurchaseRequisition::firstOrCreate(
            ['company_id' => $company->id, 'pr_number' => 'PR-'.now()->year.'-00001'],
            [
                'project_id' => $projects[0]->id ?? null,
                'department_id' => $department?->id,
                'source_material_request_id' => $materialRequest->id,
                'requested_by' => $user->id,
                'request_date' => now()->subDays(10)->toDateString(),
                'priority' => 'high',
                'required_date' => now()->addDays(4)->toDateString(),
                'justification' => 'Stock below minimum; docking schedule cannot absorb a delay.',
                'items' => [
                    ['description' => 'Welding Electrode E7018 3.2mm', 'quantity' => 40, 'unit' => 'box', 'estimated_price' => 385000],
                    ['description' => 'Steel Plate A36 10mm 1220x2440', 'quantity' => 24, 'unit' => 'sheet', 'estimated_price' => 1750000],
                ],
                'estimated_total' => 40 * 385000 + 24 * 1750000,
                'status' => 'approved',
            ]
        );

        PurchaseOrder::firstOrCreate(
            ['company_id' => $company->id, 'po_number' => 'PO-'.now()->year.'-00001'],
            [
                'vendor_id' => $vendors[0]->id ?? null,
                'purchase_requisition_id' => $requisition->id,
                'project_id' => $projects[0]->id ?? null,
                'department_id' => $department?->id,
                'po_date' => now()->subDays(7)->toDateString(),
                'delivery_date' => now()->addDays(3)->toDateString(),
                'delivery_location' => 'Main Yard Store — North Yard',
                'payment_terms' => 'Net 30',
                'currency' => 'IDR',
                'subtotal' => 57400000,
                'tax_amount' => 6314000,
                'grand_total' => 63714000,
                'requested_by' => $user->id,
                'status' => 'issued',
                'issued_by' => $user->id,
                'issued_at' => now()->subDays(7),
                'notes' => 'Partial delivery accepted. Reference PR-'.now()->year.'-00001.',
            ]
        );

        PurchaseOrder::firstOrCreate(
            ['company_id' => $company->id, 'po_number' => 'PO-'.now()->year.'-00002'],
            [
                'vendor_id' => $vendors[2]->id ?? null,
                'project_id' => null,
                'department_id' => $department?->id,
                'po_date' => now()->subDays(2)->toDateString(),
                'delivery_date' => now()->addDays(10)->toDateString(),
                'delivery_location' => 'Dockside Store — Graving Dock 2',
                'payment_terms' => 'Net 14',
                'currency' => 'IDR',
                'subtotal' => 18500000,
                'tax_amount' => 2035000,
                'grand_total' => 20535000,
                'requested_by' => $user->id,
                'status' => 'draft',
                'notes' => 'Replacement harnesses and gas detector calibration kits.',
            ]
        );
    }

    private function assets(Company $company, array $vendors, array $employees): array
    {
        $responsible = collect($employees)->firstWhere('employee_id', 'NMW-0201') ?? collect($employees)->first();

        $rows = [
            ['NMW-AST-001', 'Overhead Crane 20T — Workshop B', 'Lifting Equipment', 'North Yard — Workshop B', 'operational'],
            ['NMW-AST-002', 'Diesel Generator 250 kVA', 'Power Generation', 'Graving Dock 2', 'operational'],
            ['NMW-AST-003', 'Hydraulic Press 100T', 'Workshop Machinery', 'North Yard — Workshop A', 'under_maintenance'],
            ['NMW-AST-004', 'Air Compressor 15 bar', 'Utility', 'Graving Dock 2', 'operational'],
        ];

        $assets = [];
        foreach ($rows as $i => [$code, $name, $category, $location, $status]) {
            $assets[] = Asset::firstOrCreate(
                ['company_id' => $company->id, 'asset_code' => $code],
                [
                    'name' => $name,
                    'category' => $category,
                    'location' => $location,
                    'status' => $status,
                    'vendor_id' => $vendors[1]->id ?? null,
                    'purchase_date' => now()->subYears(3)->addMonths($i * 4)->toDateString(),
                    'responsible_employee_id' => $responsible?->id,
                ]
            );
        }

        return $assets;
    }

    /**
     * A fault reported on a real asset, and the work order raised against
     * that report. The third asset above is `under_maintenance` precisely
     * because this request is open against it — the status a browser shows and
     * the record explaining it agree.
     */
    private function maintenance(Company $company, User $user, array $assets, array $employees): void
    {
        if (MaintenanceRequest::where('company_id', $company->id)->exists()) {
            return;
        }

        $technician = collect($employees)->firstWhere('employee_id', 'NMW-0301') ?? collect($employees)->first();

        $request = MaintenanceRequest::create([
            'company_id' => $company->id,
            'request_number' => 'MRQ-'.now()->year.'-00001',
            'asset_id' => $assets[2]->id ?? null,
            'reported_by' => $user->id,
            'problem' => 'Hydraulic press losing pressure under load.',
            'description' => 'Pressure drops from 100T to roughly 60T within two minutes. Suspected seal failure on the main cylinder.',
            'priority' => 'high',
            'request_date' => now()->subDays(4)->toDateString(),
            'status' => 'approved',
        ]);

        WorkOrder::create([
            'company_id' => $company->id,
            'wo_number' => 'WO-'.now()->year.'-00001',
            'asset_id' => $assets[2]->id ?? null,
            'maintenance_request_id' => $request->id,
            'maintenance_type' => 'corrective',
            'technician_id' => $technician?->id,
            'planned_date' => now()->addDay()->toDateString(),
            'work_description' => 'Replace main cylinder seal kit, pressure-test to 100T, record result.',
            'status' => 'in_progress',
            'created_by' => $user->id,
        ]);

        WorkOrder::create([
            'company_id' => $company->id,
            'wo_number' => 'WO-'.now()->year.'-00002',
            'asset_id' => $assets[0]->id ?? null,
            'maintenance_type' => 'preventive',
            'technician_id' => $technician?->id,
            'planned_date' => now()->addDays(9)->toDateString(),
            'work_description' => 'Quarterly overhead crane inspection: hoist brake, wire rope, limit switches.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);
    }

    private function quality(Company $company, User $user): void
    {
        if (Ncr::where('company_id', $company->id)->exists()) {
            return;
        }

        Ncr::create([
            'company_id' => $company->id,
            'ncr_number' => 'NCR-'.now()->year.'-00001',
            'description' => 'Weld porosity found on shell plate seam frame 42-46 during visual inspection.',
            'severity' => 'major',
            'responsible_party' => 'Hull Fabrication',
            'status' => 'open',
            'raised_by' => $user->id,
            'raised_date' => now()->subDays(3)->toDateString(),
        ]);

        Ncr::create([
            'company_id' => $company->id,
            'ncr_number' => 'NCR-'.now()->year.'-00002',
            'description' => 'Delivered steel plate thickness measured 9.4mm against 10mm ordered.',
            'severity' => 'minor',
            'responsible_party' => 'PT Baja Sentosa Nusantara',
            'status' => 'closed',
            'raised_by' => $user->id,
            'raised_date' => now()->subDays(15)->toDateString(),
        ]);
    }
}
