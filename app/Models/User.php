<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    // HasRoles (Milestone 2, RBAC foundation) added now so
    // $user->assignRole()/->can() are available to build on -- the
    // existing role string column and isX()/isPlatformAdmin() checks
    // below are UNCHANGED and still the live authorization path for
    // every existing controller. Migrating those call sites to
    // permission-based checks is a deliberately separate, later step
    // (see docs/ADR/008), not bundled into this one so this change stays
    // reviewable and doesn't risk regressing access for any current user.
    use HasApiTokens, HasRoles, Notifiable;

    // Four-role system: Super Admin (full access), HSE (operational
    // input/management), HRD (read-only), Manager (read-only, broader
    // view scope than HRD). See the individual is*()/can*() methods below
    // for exactly what each role can do.
    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_HSE = 'hse';

    public const ROLE_HRD = 'hrd';

    public const ROLE_MANAGER = 'manager';

    /**
     * Complete Material Request Workflow (v1.6.9.1) -- "Warehouse" from
     * the spec's role table (Employee/Supervisor/Warehouse/Company Admin)
     * has no existing equivalent among the four roles above, so it's a
     * genuinely new one. Safe to add with no migration: `role` is a
     * plain VARCHAR (widened from a real ENUM in 2026_07_16_100004 for
     * exactly this kind of additive change), not a DB-level enum.
     */
    public const ROLE_WAREHOUSE = 'warehouse';

    /**
     * Milestone 2 (Tenancy Foundation). The platform operator's own staff
     * role -- distinct from every role above, which are all tenant-side
     * roles (they describe what someone can do WITHIN one customer's
     * data). A Platform Super Admin's authority is orthogonal to those:
     * it operates on Tenant/Subscription/Package records at the platform
     * level (see the forthcoming /platform/* surface, Task #44), not on
     * any tenant's Company-scoped data -- isPlatformAdmin() (tenant_id
     * null) is what actually grants that, this role label just makes it
     * visible/filterable the same way the tenant-side roles are.
     */
    public const ROLE_PLATFORM_ADMIN = 'platform_admin';

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'tenant_id',
        'company_id',
        'department_key',
        'avatar_path',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function kpiRecords()
    {
        return $this->hasMany(KpiRecord::class, 'created_by');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    /**
     * Super Admin (Developer): full, unrestricted access. This is the
     * spiritual successor of the old single "admin" role and is kept as
     * `isAdmin()` too (below) so every pre-existing policy/controller
     * check written against the v1 two-role system keeps working exactly
     * as before with zero behavior change for this role.
     */

    /**
     * Nullable (Multi-Tenant Foundation, Epic 3) -- most existing users
     * are internal staff with no single company, which is correct, not a
     * data gap. A future genuinely tenant-scoped user (e.g. a Company
     * Admin from the Company Registration flow) would have a real value
     * here.
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Milestone 2 (Tenancy Foundation). `tenant_id` null is a real,
     * permanent, intentional state -- Platform Super Admin, someone who
     * works for the platform operator, not for any customer tenant. Every
     * pre-Milestone-2 account was backfilled to the one pre-existing
     * tenant (see 2026_08_14_100043_add_tenant_id_to_users_table) and
     * keeps working exactly as before; a genuinely new Platform Super
     * Admin account is a separate, deliberate action (PlatformAdminSeeder),
     * never an accidental side effect of this column existing.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPlatformAdmin(): bool
    {
        return is_null($this->tenant_id);
    }

    /**
     * Department User mechanism (v1.10.2). A plain nullable string, not a
     * foreign key to any model -- it holds one of `workspaces.js`'s
     * `WORKSPACES` keys ('hr', 'hse', 'project-management', 'logistics',
     * or a future department's key), a frontend navigation concept, not a
     * database entity. Deliberately not the same thing as `company_id`
     * (which company a user's data is scoped to) or the existing
     * `Department` model (company-scoped org units like "Engineering" --
     * a completely different concept that happens to share the English
     * word "department").
     *
     * null (the default for every existing user -- no backfill was run)
     * means "Administrator": full Department Selector, can switch freely,
     * exactly today's behavior for every current account. Setting this
     * restricts that one user to exactly one department's sidebar with no
     * selector at all. No existing account has been assigned one as part
     * of introducing this column -- doing so would be a real access-scope
     * change (e.g. the `hse` role currently has broad cross-department
     * capability: Employees, Projects, Material Requests, Leave,
     * Incidents), and that's a policy decision for whoever administers
     * this app to make deliberately per-account, not something to infer
     * from the existing role system.
     */
    public function isDepartmentUser(): bool
    {
        return ! is_null($this->department_key);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * HSE: can input KPI, create/edit/delete Employees, manage Departments
     * & Positions in Settings. Cannot manage Companies or Users.
     */
    public function isHse(): bool
    {
        return $this->role === self::ROLE_HSE;
    }

    /**
     * HRD: read-only (dashboard, employees, reports). Unchanged from v1.
     */
    public function isHrd(): bool
    {
        return $this->role === self::ROLE_HRD;
    }

    /**
     * Manager: read-only across Dashboard, Reports, Employees, Projects.
     */
    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isWarehouse(): bool
    {
        return $this->role === self::ROLE_WAREHOUSE;
    }

    /**
     * Back-compat alias: "has full admin-equivalent capability". Used
     * throughout the v1 codebase (EmployeeController, KpiInputController,
     * policies, etc.) to mean "can create/edit/delete/input/manage".
     * Now true for both Super Admin and HSE, since the spec grants HSE
     * the same operational CRUD capability that the old single Admin
     * role had (input KPI, employee CRUD). Settings-only actions
     * (Company/User management) use isSuperAdmin() instead -- see
     * canManageSettings() / canManageUsers() below for the finer split.
     */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /**
     * Departments/Positions management in Settings: Super Admin + HSE.
     */
    public function canManageOperationalSettings(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /**
     * Company management and User Management in Settings: Super Admin only.
     */
    public function canManageSystemSettings(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * Projects module: Super Admin + HSE can create/manage; HRD and
     * Manager are view-only (Manager explicitly per spec, HRD by its
     * existing blanket read-only rule).
     */
    public function canManageProjects(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /**
     * PPE Master (types, replacement intervals): Super Admin only, per
     * v1.3 spec ("The PPE Master must be configurable by Super Admin").
     */
    public function canManagePpeMaster(): bool
    {
        return $this->isSuperAdmin();
    }

    /**
     * PPE Distribution (issuing/returning PPE to employees): Super Admin
     * + HSE, same operational tier as Employee/Project management.
     */
    public function canManagePpeDistribution(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /**
     * Daily HSE Report create/edit/delete: Super Admin + HSE.
     */
    public function canManageDailyReports(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /**
     * Material Request MVP (v1.6.8): initial rollout is HSE, matching who
     * actually uses it first -- but nothing in the module's schema or
     * queries hardcodes HSE specifically (department_id is just a plain
     * foreign key), so extending this permission to other departments
     * later is a one-line change here, not a redesign.
     */
    public function canManageMaterialRequests(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /** Leave (v1.10.0) -- same operational-staff gate as Material Request; creation/editing is staff-on-behalf-of-employee, not employee self-service (no employee login exists). */
    public function canManageLeaveRequests(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /** Incident Management (v1.10.0) -- HSE's own domain, same gate as PPE/Employees. */
    public function canManageIncidents(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /** Milestone 4, Workstream B1 -- Safety Observation. Same HSE-domain gate as Incidents; there is no separate self-service "any employee" login in this app to grant a broader creation-only permission to. */
    public function canManageSafetyObservations(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /** Milestone 4, Workstream B4-B9 -- one shared gate for the rest of HSE operations (HIRADC/JSA/PTW/Gas Test/LOTO/TBM/Inspection/Safety Equipment/HSE Materials/P3K), same isSuperAdmin()||isHse() pattern as every other HSE-domain permission above. */
    public function canManageHse(): bool
    {
        return $this->isSuperAdmin() || $this->isHse();
    }

    /** Milestones (v1.10.0) -- reuses the existing project-management permission rather than inventing a parallel one. */
    public function canManageMilestones(): bool
    {
        return $this->canManageProjects();
    }

    /** Goods Receipt (v1.10.0) -- Warehouse is the role this app already introduced specifically for receiving/processing Material Requests; receiving goods is the same responsibility. */
    public function canManageGoodsReceipts(): bool
    {
        return $this->isSuperAdmin() || $this->isWarehouse();
    }

    /**
     * Milestone 4, Workstream C (Procurement). Same "Warehouse is the
     * logistics-operations role this app already has" reasoning as
     * canManageGoodsReceipts() -- Procurement is the natural extension of
     * that same domain (PR/RFQ/PO creation and day-to-day operation), not
     * a brand-new role invented for this workstream. Financial
     * authorization (PO approval) is intentionally a SEPARATE gate --
     * see config('workflow.approvers') usage in PurchaseOrderController
     * -- so a requester/procurement officer never automatically gains
     * approval authority (segregation of duties).
     */
    public function canManageProcurement(): bool
    {
        return $this->isSuperAdmin() || $this->isWarehouse();
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }

    /**
     * Milestone 3 (UAT #1/#3/#7 -- identity clarity). Display label only
     * -- the underlying `role` column value stays `super_admin`/
     * `platform_admin` unchanged (renaming the stored string would cascade
     * into `config/workflow.php`, every `role:super_admin` route
     * middleware, `RolePermissionSeeder`'s Spatie role names, and more,
     * for zero behavioral gain). What UAT actually flagged was confusion
     * in the UI between "the tenant's own full admin" and "the platform
     * operator" -- both were labeled some variant of "Super Admin." Fixed
     * here, at the single place every label already flowed through:
     *   - `super_admin` (tenant-side, full access to their own tenant)
     *     now reads "Administrator" -- matches the SaaS hierarchy's own
     *     naming (Platform -> Master, Tenant -> Administrator -> ...).
     *   - `platform_admin` now reads "Master" for the same reason.
     * See docs/ADR/015-identity-hierarchy-clarity.md.
     */
    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_SUPER_ADMIN => 'Administrator',
            self::ROLE_HSE => 'HSE',
            self::ROLE_HRD => 'HRD',
            self::ROLE_MANAGER => 'Manager',
            self::ROLE_WAREHOUSE => 'Warehouse',
            self::ROLE_PLATFORM_ADMIN => 'Master',
            default => ucfirst($this->role),
        };
    }
}
