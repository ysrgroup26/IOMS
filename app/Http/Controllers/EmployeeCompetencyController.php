<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeCompetencyRequest;
use App\Http\Requests\UpdateEmployeeCompetencyRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeCompetency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Milestone 4, Workstream A2. Per-employee training/certification
 * records -- surfaced on the Employee Profile page (mirrors how PPE
 * assignment doesn't get its own top-level "add" flow separate from
 * where it's actually used).
 *
 * IDOR guard: `Employee`/`EmployeeCompetency` route-model-binding has NO
 * tenant check anywhere in this codebase -- confirmed live (a second
 * tenant's admin could load `/employees/{id}` for ANY employee ID across
 * ANY tenant; `EmployeePolicy::view()` unconditionally returns true and
 * `EmployeeController` never calls `authorize()` on `show()`). That is a
 * broader, pre-existing gap flagged separately for its own fix (too many
 * call sites -- EmployeeController, PpeController, KpiInputController,
 * ProjectController's manpower actions, etc. -- to safely change as a
 * side effect of this feature). Every method here adds its OWN explicit
 * tenant check instead of trusting the bound model alone, so this new
 * feature doesn't inherit that gap regardless of what happens to the
 * rest. `abort(404)`, not 403, to avoid confirming a foreign employee id
 * exists at all.
 */
class EmployeeCompetencyController extends Controller
{
    /**
     * v2.108.1 -- CERTIFICATES GO TO THE PRIVATE DISK.
     *
     * `config/filesystems.php` names "competency certificates" as one of
     * the tenant-sensitive documents the `private` disk exists for; they
     * were being written to the public one, reachable by URL with no
     * authentication and no tenant check. New uploads land privately and
     * are served only through SecureDocumentController.
     *
     * Files written before this change are NOT moved -- that is an
     * irreversible bulk operation on production data and needs its own
     * sign-off (the same reasoning SecureDocumentController records for
     * the wider migration). They keep working: the controller streams
     * from whichever disk holds the file.
     */
    private function storeCertificate(UploadedFile $file): string
    {
        return $file->store('competency', 'private');
    }

    /** Deletes a stored certificate from whichever disk actually holds it. */
    private function discardCertificate(?string $path): void
    {
        if (! $path) {
            return;
        }

        foreach (['private', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);

                return;
            }
        }
    }

    public function store(StoreEmployeeCompetencyRequest $request, Employee $employee): RedirectResponse
    {
        $this->assertEmployeeInCurrentTenant($employee);

        $data = $request->validated();

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $this->storeCertificate($request->file('attachment'));
        }

        $data['employee_id'] = $employee->id;
        $data['created_by'] = $request->user()->id;

        $competency = EmployeeCompetency::create($data);

        ActivityLog::record('created', "Competency \"{$competency->competencyType->name}\" recorded for {$employee->full_name}.", $competency);

        return back()->with('success', 'Competency record added.');
    }

    public function update(UpdateEmployeeCompetencyRequest $request, EmployeeCompetency $employeeCompetency): RedirectResponse
    {
        $this->assertEmployeeInCurrentTenant($employeeCompetency->employee);

        $data = $request->validated();

        if ($request->hasFile('attachment')) {
            $this->discardCertificate($employeeCompetency->attachment_path);
            $data['attachment_path'] = $this->storeCertificate($request->file('attachment'));
        }

        $employeeCompetency->update($data);

        ActivityLog::record('updated', "Competency record for {$employeeCompetency->employee->full_name} was updated.", $employeeCompetency);

        return back()->with('success', 'Competency record updated.');
    }

    public function destroy(EmployeeCompetency $employeeCompetency): RedirectResponse
    {
        abort_unless(request()->user()->isAdmin(), 403);
        $this->assertEmployeeInCurrentTenant($employeeCompetency->employee);

        $employeeName = $employeeCompetency->employee->full_name;
        $competencyName = $employeeCompetency->competencyType->name;

        // Either disk: a certificate uploaded before v2.108.1 is public,
        // one uploaded after is private, and deleting the record must not
        // leave the file behind in either case.
        $this->discardCertificate($employeeCompetency->attachment_path);

        $employeeCompetency->delete();

        ActivityLog::record('deleted', "Competency \"{$competencyName}\" removed from {$employeeName}.");

        return back()->with('success', 'Competency record removed.');
    }

    private function assertEmployeeInCurrentTenant(Employee $employee): void
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        abort_unless($tenantCompanyIds->contains($employee->company_id), 404);
    }
}
