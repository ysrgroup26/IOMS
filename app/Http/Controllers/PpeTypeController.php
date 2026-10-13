<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePpeTypeRequest;
use App\Http\Requests\UpdatePpeTypeRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\PpeType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * PPE Master. Table-driven per spec -- Super Admin can add/edit any PPE
 * type and its replacement interval without a code change. Consumed as a
 * dropdown source by EmployeePpeController (Distribution).
 */
class PpeTypeController extends Controller
{
    /**
     * v2.106.0 -- A TYPE A CUSTOMER ADDS BELONGS TO THAT CUSTOMER.
     *
     * It used to be written with no owner, which put it in the shared
     * built-in catalogue: one customer adding "Sarung Tangan Las" made it
     * appear in every other customer's dropdown.
     */
    public function store(StorePpeTypeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->assertCompanyAccess((int) $data['company_id']);
        $this->assertNameAvailable($data['name'], (int) $data['company_id']);

        $ppeType = PpeType::create([
            ...$data,
            'source_default_id' => null,
        ]);

        ActivityLog::record('created', "PPE type \"{$ppeType->name}\" was created.", $ppeType);

        return back()->with('success', 'PPE type added.');
    }

    /**
     * v2.106.0 -- THE SHARED BUILT-INS ARE NOT A TENANT'S TO REWRITE.
     *
     * This had no ownership check of any kind, and `PpeTypePolicy` only
     * asks about a role capability -- because on a shared row there was no
     * ownership to ask about. So any customer's administrator could rename
     * or re-interval a PPE type every other customer was using, and
     * renaming is as destructive to the customer reading the other end of
     * it as deleting would be. `destroy()` below had already noticed half
     * of this problem.
     */
    public function update(UpdatePpeTypeRequest $request, PpeType $ppeType): RedirectResponse
    {
        $data = $request->validated();
        $companyId = (int) $data['company_id'];
        $this->assertCompanyAccess($companyId);

        if ($ppeType->isSharedDefault()) {
            $override = PpeType::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('source_default_id', $ppeType->id)
                ->first();
            $this->assertNameAvailable($data['name'], $companyId, $override?->id, $ppeType->id);

            $override ??= new PpeType(['company_id' => $companyId, 'source_default_id' => $ppeType->id]);
            $override->fill(collect($data)->except('company_id')->all());
            $override->save();
            $ppeType = $override;
        } else {
            abort_unless($ppeType->company_id === $companyId, 404);
            $this->assertNameAvailable($data['name'], $companyId, $ppeType->id);
            $ppeType->update(collect($data)->except('company_id')->all());
        }

        ActivityLog::record('updated', "PPE type \"{$ppeType->name}\" was updated.", $ppeType);

        return back()->with('success', 'PPE type updated.');
    }

    public function destroy(\Illuminate\Http\Request $request, PpeType $ppeType): RedirectResponse
    {
        $this->authorize('delete', $ppeType);

        $companyId = (int) $request->input('company_id');
        $this->assertCompanyAccess($companyId);

        if ($ppeType->isSharedDefault()) {
            $override = PpeType::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('source_default_id', $ppeType->id)
                ->first();
            $override ??= new PpeType([
                'company_id' => $companyId,
                'source_default_id' => $ppeType->id,
                'name' => $ppeType->name,
                'replacement_interval_months' => $ppeType->replacement_interval_months,
            ]);
            $override->is_active = false;
            $override->save();

            return back()->with('success', 'PPE type deactivated for this Operating Unit.');
        }

        abort_unless($ppeType->company_id === $companyId, 404);

        if ($ppeType->source_default_id) {
            $ppeType->is_active = false;
            $ppeType->save();

            return back()->with('success', 'PPE type deactivated for this Operating Unit.');
        }

        // v2.63.0 -- THIS GUARD ASKS AN INSTALLATION-WIDE QUESTION, so it
        // must ignore the tenant scope.
        //
        // `ppe_types` is deliberately shared reference data (see the class
        // comment), but `employee_ppe` became tenant-scoped in v2.62.0. A
        // plain `assignments()->exists()` therefore started answering "is
        // MY tenant using this type", which would have let one customer
        // delete a type another customer is actively issuing -- orphaning
        // their PPE records. "Is anyone anywhere using this row" is the
        // only correct question to ask about a shared row.
        if ($ppeType->assignments()->withoutGlobalScopes()->exists()) {
            return back()->with('error', 'Cannot delete a PPE type that has been issued to employees.');
        }

        $name = $ppeType->name;
        $ppeType->delete();

        ActivityLog::record('deleted', "PPE type \"{$name}\" was removed.");

        return back()->with('success', 'PPE type removed.');
    }

    private function assertCompanyAccess(int $companyId): void
    {
        abort_unless(Company::query()->whereKey($companyId)->exists(), 404);
    }

    private function assertNameAvailable(string $name, int $companyId, ?int $ignoreId = null, ?int $sharedId = null): void
    {
        $duplicate = PpeType::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        $sharedDuplicate = PpeType::withoutGlobalScopes()
            ->whereNull('company_id')->where('name', $name)
            ->when($sharedId, fn ($q) => $q->whereKeyNot($sharedId))
            ->exists();

        if ($duplicate || $sharedDuplicate) {
            throw ValidationException::withMessages(['name' => 'A PPE type with this name already exists in this Operating Unit.']);
        }
    }
}
