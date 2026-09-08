<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePpeTypeRequest;
use App\Http\Requests\UpdatePpeTypeRequest;
use App\Models\ActivityLog;
use App\Models\PpeType;
use Illuminate\Http\RedirectResponse;

/**
 * PPE Master. Table-driven per spec -- Super Admin can add/edit any PPE
 * type and its replacement interval without a code change. Consumed as a
 * dropdown source by EmployeePpeController (Distribution).
 */
class PpeTypeController extends Controller
{
    public function store(StorePpeTypeRequest $request): RedirectResponse
    {
        $ppeType = PpeType::create($request->validated());

        ActivityLog::record('created', "PPE type \"{$ppeType->name}\" was created.", $ppeType);

        return back()->with('success', 'PPE type added.');
    }

    public function update(UpdatePpeTypeRequest $request, PpeType $ppeType): RedirectResponse
    {
        $ppeType->update($request->validated());

        ActivityLog::record('updated', "PPE type \"{$ppeType->name}\" was updated.", $ppeType);

        return back()->with('success', 'PPE type updated.');
    }

    public function destroy(PpeType $ppeType): RedirectResponse
    {
        $this->authorize('delete', $ppeType);

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
}
