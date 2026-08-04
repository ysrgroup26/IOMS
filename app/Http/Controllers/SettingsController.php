<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreKpiCategoryRequest;
use App\Http\Requests\UpdateAuthenticationRequest;
use App\Http\Requests\UpdateKpiCategoryRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Department;
use App\Models\KpiCategory;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings module. Two permission tiers, enforced both by route middleware
 * (see routes/web.php: role:super_admin,hse for operational settings vs
 * role:super_admin for Company/User management) and by policy/manual
 * checks below as defense-in-depth:
 *   - Departments & Positions: Super Admin + HSE (canManageOperationalSettings)
 *   - Companies, Users, Backup/Restore, app branding: Super Admin only (canManageSystemSettings)
 */
class SettingsController extends Controller
{
    /**
     * Company-Scoped Master Data (v1.6.10). Departments and Positions
     * are now filtered by an optional `company_id` query param, matching
     * the exact same per-request filter pattern already used everywhere
     * else in this app (Dashboard, PPE, Reports, Employees) -- no
     * persistent "Active Company" session concept exists anywhere in
     * this codebase to reuse instead, so this follows the established
     * convention rather than introducing a new one.
     */
    public function index(Request $request): Response
    {
        $companyId = $request->input('company_id') ? (int) $request->input('company_id') : null;

        return Inertia::render('Settings/Index', [
            'company' => [
                'name' => CompanySetting::get('company_name'),
                'subtitle' => CompanySetting::get('company_subtitle'),
                'logo_path' => CompanySetting::get('company_logo_path'),
            ],
            'companies' => Company::withCount(['employees', 'departments'])->orderBy('name')->get(),
            'departments' => Department::with('company:id,name')->withCount('employees')->inCompany($companyId)->ordered()->get(),
            'positions' => Position::with('company:id,name', 'department:id,name')->inCompany($companyId)->ordered()->get(),
            'kpiCategories' => KpiCategory::with('company:id,name')->orderBy('sort_order')->orderBy('name')->get(),
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'is_active', 'last_login_at']),
            'filters' => ['company_id' => $companyId],
            'can' => [
                'manage_operational' => request()->user()->canManageOperationalSettings(),
                'manage_system' => request()->user()->canManageSystemSettings(),
            ],
        ]);
    }

    public function updateCompany(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_subtitle' => ['nullable', 'string', 'max:255'],
            'company_short_name' => ['nullable', 'string', 'max:50'],
            'footer_copyright' => ['nullable', 'string', 'max:255'],
            // Laravel's `image` rule does NOT include SVG by default --
            // explicit mimes list needed to actually support the SVG
            // upload requested (v1.6.3).
            'logo' => ['nullable', 'mimes:jpg,jpeg,png,svg,webp', 'max:2048'],
            'favicon' => ['nullable', 'mimes:jpg,jpeg,png,svg,webp,ico', 'max:512'],
        ]);

        CompanySetting::set('company_name', $validated['company_name']);
        CompanySetting::set('company_subtitle', $validated['company_subtitle'] ?? 'Industrial Operations Platform');
        CompanySetting::set('company_short_name', $validated['company_short_name'] ?? '');
        CompanySetting::set('footer_copyright', $validated['footer_copyright'] ?? '');

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('uploads/company', 'public');
            CompanySetting::set('company_logo_path', $path);
        }

        if ($request->hasFile('favicon')) {
            $path = $request->file('favicon')->store('uploads/company', 'public');
            CompanySetting::set('company_favicon_path', $path);
        }

        ActivityLog::record('updated', 'Application branding settings updated.');

        return back()->with('success', 'Settings updated.');
    }

    // --- Modules (Super Admin only: enable/disable sidebar modules) ---

    /**
     * Toggles which sidebar modules are enabled app-wide. Core modules
     * (Home, Dashboard, Settings) are never in this list -- they're
     * always on, see config/modules.php. Every key submitted must be a
     * real, registered module (config/modules.php is the whitelist), so
     * this can never be used to "enable" something that doesn't exist.
     */
    public function updateModules(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enabled_modules' => ['array'],
            'enabled_modules.*' => ['string', Rule::in(array_keys(config('modules.available')))],
        ]);

        CompanySetting::set('enabled_modules', json_encode($validated['enabled_modules'] ?? []));

        ActivityLog::record('updated', 'Enabled modules were updated.');

        return back()->with('success', 'Modules updated.');
    }

    // --- Companies (business entities: GAJ, Maintenance) ---

    public function storeCompanyEntity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:companies,name'],
            'code' => ['nullable', 'string', 'max:20', 'unique:companies,code'],
        ]);

        Company::create($data);

        return back()->with('success', 'Company added.');
    }

    public function updateCompanyEntity(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('companies', 'name')->ignore($company->id)],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('companies', 'code')->ignore($company->id)],
            'is_active' => ['boolean'],
        ]);

        $company->update($data);

        return back()->with('success', 'Company updated.');
    }

    public function destroyCompanyEntity(Company $company): RedirectResponse
    {
        if ($company->employees()->exists() || $company->departments()->exists()) {
            return back()->with('error', 'Cannot delete a company that still has departments or employees assigned.');
        }

        $company->delete();

        return back()->with('success', 'Company removed.');
    }

    // --- Departments ---

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('company_id', $request->input('company_id'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'code' => ['nullable', 'string', 'max:20', 'unique:departments,code'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        Department::create($data);

        return back()->with('success', 'Department added.');
    }

    public function updateDepartment(Request $request, Department $department): RedirectResponse
    {
        $companyId = $request->input('company_id', $department->company_id);

        $data = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('departments', 'name')->where('company_id', $companyId)->ignore($department->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'code' => ['nullable', 'string', 'max:20', Rule::unique('departments', 'code')->ignore($department->id)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);

        $department->update($data);

        return back()->with('success', 'Department updated.');
    }

    public function destroyDepartment(Department $department): RedirectResponse
    {
        if ($department->employees()->exists()) {
            return back()->with('error', 'Cannot delete a department that still has employees assigned.');
        }

        $department->delete();

        return back()->with('success', 'Department removed.');
    }

    // --- Positions ---

    public function storePosition(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'company_id' => ['required', 'exists:companies,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        Position::create($data);

        return back()->with('success', 'Position added.');
    }

    public function updatePosition(Request $request, Position $position): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'company_id' => ['required', 'exists:companies,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);

        $position->update($data);

        return back()->with('success', 'Position updated.');
    }

    public function destroyPosition(Position $position): RedirectResponse
    {
        $position->delete();

        return back()->with('success', 'Position removed.');
    }

    // --- KPI Categories (Super Admin + HSE) ---
    //
    // KPI categories are fully data-driven -- never hardcoded. A category
    // with company_id = null applies to every company; one with company_id
    // set only applies to that company, so different companies can run
    // entirely different KPI sets (e.g. TRIR/LTIFR/Near Miss vs. Safety
    // Patrol/Training/Toolbox Meeting) with zero code changes. Dashboard,
    // Reports, and Input KPI all read from this table live.

    public function storeKpiCategory(StoreKpiCategoryRequest $request): RedirectResponse
    {
        $category = KpiCategory::create($request->validated() + ['is_active' => true]);

        ActivityLog::record('created', "KPI category \"{$category->name}\" was created.", $category);

        return back()->with('success', 'KPI category added.');
    }

    public function updateKpiCategory(UpdateKpiCategoryRequest $request, KpiCategory $kpiCategory): RedirectResponse
    {
        $kpiCategory->update($request->validated());

        ActivityLog::record('updated', "KPI category \"{$kpiCategory->name}\" was updated.", $kpiCategory);

        return back()->with('success', 'KPI category updated.');
    }

    public function destroyKpiCategory(KpiCategory $kpiCategory): RedirectResponse
    {
        if ($kpiCategory->kpiRecords()->exists()) {
            return back()->with('error', 'Cannot delete a KPI category that already has recorded data. Deactivate it instead.');
        }

        $name = $kpiCategory->name;
        $kpiCategory->delete();

        ActivityLog::record('deleted', "KPI category \"{$name}\" was removed.");

        return back()->with('success', 'KPI category removed.');
    }

    // --- User Management (Super Admin only) ---

    public function storeUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'in:super_admin,hse,hrd,manager,warehouse'],
        ]);

        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);

        ActivityLog::record('created', "User {$user->name} ({$user->role}) was created.", $user);

        return back()->with('success', 'User created.');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['required', 'in:super_admin,hse,hrd,manager,warehouse'],
            'is_active' => ['boolean'],
        ]);

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $user->update($data);

        ActivityLog::record('updated', "User {$user->name} was updated.", $user);

        return back()->with('success', 'User updated.');
    }

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $name = $user->name;
        $user->delete();

        ActivityLog::record('deleted', "User {$name} was removed.", $user);

        return back()->with('success', 'User removed.');
    }

    // --- Backup / Restore (Super Admin only) ---

    /**
     * Creates a mysqldump-based backup file and streams it for download.
     * Requires the `mysqldump` binary to be available on the server PATH.
     */
    public function backupDatabase(): mixed
    {
        $filename = 'ioms-backup-'.now()->format('Y-m-d_His').'.sql';
        $path = storage_path('app/backups/'.$filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $db = config('database.connections.mysql');
        $command = sprintf(
            'mysqldump -h%s -P%s -u%s %s %s > %s 2>&1',
            escapeshellarg($db['host']),
            escapeshellarg($db['port']),
            escapeshellarg($db['username']),
            $db['password'] ? '-p'.escapeshellarg($db['password']) : '',
            escapeshellarg($db['database']),
            escapeshellarg($path)
        );

        exec($command, $output, $exitCode);

        if ($exitCode !== 0 || ! file_exists($path)) {
            return back()->with('error', 'Backup failed. Ensure mysqldump is installed and accessible on the server.');
        }

        ActivityLog::record('exported', 'Database backup created and downloaded.');

        return response()->download($path)->deleteFileAfterSend(true);
    }

    /**
     * Restores the database from an uploaded .sql backup file.
     * DESTRUCTIVE: overwrites current data. Super-Admin-only, confirmed client-side.
     */
    public function restoreDatabase(Request $request): RedirectResponse
    {
        $request->validate([
            'backup_file' => ['required', 'file', 'mimes:sql', 'max:51200'],
        ]);

        $uploadPath = $request->file('backup_file')->store('restores', 'local');
        $fullPath = storage_path('app/'.$uploadPath);

        $db = config('database.connections.mysql');
        $command = sprintf(
            'mysql -h%s -P%s -u%s %s %s < %s 2>&1',
            escapeshellarg($db['host']),
            escapeshellarg($db['port']),
            escapeshellarg($db['username']),
            $db['password'] ? '-p'.escapeshellarg($db['password']) : '',
            escapeshellarg($db['database']),
            escapeshellarg($fullPath)
        );

        exec($command, $output, $exitCode);
        Storage::disk('local')->delete($uploadPath);

        if ($exitCode !== 0) {
            return back()->with('error', 'Restore failed. Please verify the backup file is valid.');
        }

        ActivityLog::record('restored', 'Database restored from uploaded backup file.');

        return back()->with('success', 'Database restored successfully.');
    }

    // --- Authentication (self-service: change your OWN email/password) ---

    /**
     * Lets the currently-authenticated Super Admin/HSE user change their
     * own login email ("Username") and/or password. Requires the current
     * password to confirm identity. Future-ready for Email verification
     * and 2FA fields, per the spec -- neither is implemented yet, this
     * only adds the credential-change flow itself.
     */
    public function updateAuthentication(UpdateAuthenticationRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->email = $data['email'];
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        ActivityLog::record('updated', 'Changed their own login credentials.', $user);

        return back()->with('success', 'Your credentials have been updated.');
    }
}
