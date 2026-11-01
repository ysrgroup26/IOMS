<?php

namespace Database\Seeders;

use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowVersion;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\Position;
use App\Models\PtwWorkflowSetting;
use App\Models\Subscription;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ApprovalEngine;
use App\Services\Approvals\ApprovalFlowValidator;
use App\Services\PtwWorkflowService;
use App\Support\CurrentTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * v2.141.0 -- AN ORGANISATION TO REVIEW THE APPROVAL FLOW BUILDER ON (staging).
 *
 * One Business tenant, "Flow Review (Test)", with two operating units so
 * both PTW routes can be seen side by side:
 *
 *   Yard Utama  approval_mode = flow    (Company Approval Flow, A8/A8b)
 *   Yard Dua    approval_mode = legacy  (verify -> approve gates, unchanged)
 *
 * and a company structure with positions, so a flow can name a ROLE, a
 * POSITION and a PERSON. The PTW flow:
 *
 *   Approval 1  Position: Superintendent
 *   Approval 2  Role: HSE
 *   Final Verification  Position: Project Manager  OR  Role: HSE
 *
 * which makes separation of duties visible: the HSE officer who approved
 * stage 2 cannot verify the same permit, the other one can.
 *
 * Permits are left at every interesting point: waiting at stage 1, waiting
 * at Final Verification, approved, and one on the legacy unit. Then the
 * flow is edited (version 2) so a reviewer sees permits under way keep
 * version 1 while new ones take version 2. A Department User has a task
 * assigned across departments (D1).
 *
 * Accounts (password: IOMS_PILOT_PASSWORD, i.e. the staging app password):
 *   flow.admin@ioms.local       Company Admin
 *   flow.foreman@ioms.local     Foreman (Warehouse, PTW Access) -- requester
 *   flow.super@ioms.local       Superintendent (position)
 *   flow.hse1@ioms.local        HSE Officer 1
 *   flow.hse2@ioms.local        HSE Officer 2
 *   flow.pm1@ioms.local         Project Manager (position)
 *   flow.pm2@ioms.local         Project Manager (position)
 *   flow.dept@ioms.local        Department User (Production), D1
 *
 * Test data only; refuses to run in production. Idempotent for accounts,
 * structure and flows; permits and tasks are created once.
 *
 *   php artisan db:seed --class=ApprovalFlowReviewSeeder
 */
class ApprovalFlowReviewSeeder extends Seeder
{
    public const TENANT_SLUG = 'flow-review';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('ApprovalFlowReviewSeeder creates test accounts and does not run in production.');
        }

        $package = Package::where('slug', 'business')->first()
            ?? throw new RuntimeException('No `business` package exists; run PackageSeeder first.');

        $tenant = Tenant::firstOrCreate(['slug' => self::TENANT_SLUG], ['name' => 'Flow Review (Test)', 'status' => Tenant::STATUS_ACTIVE]);
        $main = Company::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'FLWUTM'], ['name' => 'Yard Utama', 'is_active' => true]);
        $second = Company::withoutGlobalScopes()->firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'FLWDUA'], ['name' => 'Yard Dua', 'is_active' => true]);

        Subscription::withoutGlobalScopes()->updateOrCreate(['tenant_id' => $tenant->id], [
            'package_id' => $package->id, 'type' => Subscription::TYPE_SUBSCRIPTION, 'status' => Subscription::STATUS_ACTIVE,
            'billing_cycle' => Subscription::CYCLE_YEARLY, 'billing_mode' => Subscription::BILLING_MODE_MANUAL,
            'starts_at' => now(), 'ends_at' => now()->addYear(),
            'agreed_price_monthly' => $package->price_monthly, 'agreed_price_yearly' => $package->price_yearly,
            'agreed_currency' => $package->currency, 'notes' => 'Approval Flow Builder review tenant (ApprovalFlowReviewSeeder).',
        ]);
        $tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        $tenant->modules()->sync(Module::whereIn('key', $package->defaultModuleKeys())->pluck('id'));

        $password = (string) (env('IOMS_PILOT_PASSWORD') ?: 'password');
        $previous = app(CurrentTenant::class)->get();
        app(CurrentTenant::class)->set($tenant);

        try {
            $production = Department::withoutGlobalScopes()->firstOrCreate(['code' => 'FLW-PRD'], ['name' => 'Production', 'company_id' => $main->id]);
            $positions = [];
            foreach (['Superintendent', 'Project Manager', 'HSE Officer', 'Foreman'] as $name) {
                $positions[$name] = Position::withoutGlobalScopes()->firstOrCreate(['company_id' => $main->id, 'name' => $name], ['is_active' => true, 'department_id' => $production->id]);
            }

            $u = fn (string $key, string $name, string $role, ?string $position, array $extra = []) => $this->account($tenant, $main, $production, "flow.{$key}@ioms.local", $name, $role, $position ? $positions[$position] : null, $password, $extra);

            $admin = $u('admin', 'Flow Admin', User::ROLE_SUPER_ADMIN, null);
            $foreman = $u('foreman', 'Budi Foreman', User::ROLE_WAREHOUSE, 'Foreman', ['ptw_access' => true]);
            $super = $u('super', 'Sari Superintendent', User::ROLE_MANAGER, 'Superintendent');
            $hse1 = $u('hse1', 'Hendra HSE', User::ROLE_HSE, 'HSE Officer');
            $hse2 = $u('hse2', 'Hana HSE', User::ROLE_HSE, 'HSE Officer');
            $pm1 = $u('pm1', 'Putra Project Manager', User::ROLE_MANAGER, 'Project Manager');
            $u('pm2', 'Prita Project Manager', User::ROLE_MANAGER, 'Project Manager');
            $dept = $u('dept', 'Dodi Production', User::ROLE_WAREHOUSE, null, ['department_key' => 'logistics']);

            $flow = ApprovalFlow::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'module_key' => 'permit_to_work', 'name' => 'PTW Approval'],
                ['company_id' => null, 'is_active' => true, 'priority' => 0],
            );

            $firstRun = ! PermitToWork::withoutGlobalScopes()->whereIn('company_id', [$main->id, $second->id])->exists();

            if ($firstRun) {
                $this->steps($flow, [
                    ['step_number' => 1, 'kind' => 'approval', 'mode' => 'single', 'approver_position_id' => $positions['Superintendent']->id],
                    ['step_number' => 2, 'kind' => 'approval', 'mode' => 'parallel_any', 'approver_role' => User::ROLE_HSE, 'escalate_after_hours' => 24, 'escalate_to_role' => User::ROLE_SUPER_ADMIN],
                    ['step_number' => 3, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_position_id' => $positions['Project Manager']->id],
                    ['step_number' => 3, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => User::ROLE_HSE],
                ], $admin);
            }

            PtwWorkflowSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $main->id], ['approval_mode' => PtwWorkflowService::MODE_FLOW]);
            PtwWorkflowSetting::withoutGlobalScopes()->updateOrCreate(['company_id' => $second->id], ['approval_mode' => PtwWorkflowService::MODE_LEGACY]);

            if ($firstRun) {
                $this->scenarios($main, $second, $flow, $admin, $foreman, $super, $hse1, $hse2, $pm1, $dept, $positions);
            }
        } finally {
            app(CurrentTenant::class)->set($previous);
        }
    }

    private function scenarios(Company $main, Company $second, ApprovalFlow $flow, User $admin, User $foreman, User $super, User $hse1, User $hse2, User $pm1, User $dept, array $positions): void
    {
        $engine = app(ApprovalEngine::class);
        $ptw = app(PtwWorkflowService::class);

        $submit = function (Company $company, string $work) use ($foreman, $ptw): PermitToWork {
            $permit = PermitToWork::create([
                'ptw_number' => PermitToWork::generateNumber(), 'company_id' => $company->id, 'permit_type' => 'hot_work',
                'work_reference' => $work, 'location' => 'Dok 2', 'work_description' => 'Data uji untuk peninjauan Approval Flow.',
                'start_datetime' => now()->addDay()->setTime(8, 0), 'end_datetime' => now()->addDay()->setTime(16, 0),
                'requested_by' => $foreman->id, 'status' => PermitToWork::STATUS_DRAFT,
            ]);
            // Asked BEFORE submitting, as the controller does: a draft follows
            // its unit's mode.
            $onFlow = $ptw->usesApprovalFlow($permit);
            $permit->transitionTo(PermitToWork::STATUS_SUBMITTED, $foreman);
            if ($onFlow) {
                $ptw->startFlow($permit, $foreman);
            }

            return $permit->fresh();
        };
        $decide = fn (PermitToWork $permit, User $by) => $engine->decide(
            $permit->approvals()->where('status', Approval::STATUS_PENDING)->orderBy('id')->firstOrFail(), $by, Approval::STATUS_APPROVED, null,
        );

        // 1. Waiting at stage 1 (Superintendent).
        $submit($main, 'Pengelasan pipa dok 2');

        // 2. Waiting at Final Verification. Hendra (HSE) approved stage 2,
        //    so Hendra cannot verify; Hana, Putra or Prita can.
        $atFinal = $submit($main, 'Penggantian pelat lambung');
        $decide($atFinal, $super);
        $decide($atFinal, $hse1);

        // 3. Fully through: verified by a Project Manager.
        $done = $submit($main, 'Pengecatan tangki ballast');
        $decide($done, $super);
        $decide($done, $hse2);
        $decide($done, $pm1);

        // 4. The legacy unit: verify -> approve gates, no flow rows.
        $submit($second, 'Perbaikan crane dermaga');

        // 5. The flow is edited AFTER those permits started: version 2 adds a
        //    Manager approval. Permits 1 and 2 keep version 1.
        $this->steps($flow, [
            ['step_number' => 1, 'kind' => 'approval', 'mode' => 'single', 'approver_position_id' => $positions['Superintendent']->id],
            ['step_number' => 2, 'kind' => 'approval', 'mode' => 'parallel_any', 'approver_role' => User::ROLE_HSE, 'escalate_after_hours' => 24, 'escalate_to_role' => User::ROLE_SUPER_ADMIN],
            ['step_number' => 3, 'kind' => 'approval', 'mode' => 'single', 'approver_user_id' => $pm1->id],
            ['step_number' => 4, 'kind' => 'final_verification', 'mode' => 'parallel_any', 'approver_role' => User::ROLE_HSE],
        ], $admin);
        $submit($main, 'Pemasangan scaffolding (versi 2)');

        // A Material Request flow, to show the editor on a non-PTW module.
        $mrFlow = ApprovalFlow::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $main->tenant_id, 'module_key' => 'material_request', 'name' => 'Material Request Approval'],
            ['company_id' => null, 'is_active' => true, 'priority' => 0],
        );
        $this->steps($mrFlow, [
            ['step_number' => 1, 'kind' => 'approval', 'mode' => 'single', 'approver_position_id' => $positions['Superintendent']->id],
            ['step_number' => 2, 'kind' => 'approval', 'mode' => 'single', 'approver_role' => User::ROLE_MANAGER],
        ], $admin);

        // D1: a task assigned across departments to a Department User.
        Task::create([
            'task_number' => 'TSK-FLW-001', 'title' => 'Siapkan material untuk pengelasan dok 2', 'company_id' => $main->id,
            'created_by' => $super->id, 'assigned_user_id' => $dept->id, 'status' => Task::STATUS_OPEN,
            'priority' => Task::PRIORITY_HIGH, 'due_date' => now()->addDays(2)->toDateString(),
        ]);
    }

    private function steps(ApprovalFlow $flow, array $steps, User $publisher): void
    {
        // The same check a save in Settings makes: review data is never a
        // configuration the product itself would refuse.
        app(ApprovalFlowValidator::class)->assertValid($flow->module_key, $steps, $flow->tenant_id);

        $flow->steps()->withoutGlobalScopes()->get()->each->delete();
        foreach ($steps as $step) {
            $flow->steps()->create($step);
        }
        ApprovalFlowVersion::currentFor($flow->fresh(), $publisher);
    }

    private function account(Tenant $tenant, Company $company, Department $department, string $email, string $name, string $role, ?Position $position, string $password, array $extra = []): User
    {
        $employeeId = null;
        if ($position) {
            $employee = Employee::withoutGlobalScopes()->firstOrCreate(
                ['employee_id' => 'FLW-'.strtoupper(substr(md5($email), 0, 6))],
                ['full_name' => $name, 'company_id' => $company->id, 'department_id' => $department->id, 'position_id' => $position->id, 'status' => 'active', 'join_date' => now()->subYear()->toDateString()],
            );
            $employeeId = $employee->id;
        }

        $user = User::withoutGlobalScopes()->firstOrNew(['email' => $email]);
        $user->forceFill(array_merge([
            'name' => $name, 'password' => Hash::make($password), 'role' => $role,
            'tenant_id' => $tenant->id, 'company_id' => $company->id, 'is_active' => true,
            'email_verified_at' => $user->email_verified_at ?? now(), 'employee_id' => $employeeId,
            'department_key' => null, 'ptw_access' => $role === User::ROLE_HSE,
        ], $extra))->save();

        return $user;
    }
}
