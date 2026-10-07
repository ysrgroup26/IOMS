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
    /**
     * v2.106.0 -- A TYPE A CUSTOMER ADDS BELONGS TO THAT CUSTOMER.
     *
     * It used to be written with no owner, which put it in the shared
     * built-in catalogue: one customer adding "Sarung Tangan Las" made it
     * appear in every other customer's dropdown.
     */
    public function store(StorePpeTypeRequest $request): RedirectResponse
    {
        $ppeType = PpeType::create([
            ...$request->validated(),
            'company_id' => $request->user()->company_id,
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
        if ($ppeType->isSharedDefault()) {
            return back()->with('error',
                'Jenis APD bawaan tidak dapat diubah. Tambahkan jenis APD milik organisasi Anda bila perlu yang berbeda.');
        }

        $ppeType->update($request->validated());

        ActivityLog::record('updated', "PPE type \"{$ppeType->name}\" was updated.", $ppeType);

        return back()->with('success', 'PPE type updated.');
    }

    public function destroy(PpeType $ppeType): RedirectResponse
    {
        $this->authorize('delete', $ppeType);

        // v2.106.0: the same boundary as update(). A built-in belongs to the
        // installation, not to whichever customer happens to be looking at it.
        if ($ppeType->isSharedDefault()) {
            return back()->with('error',
                'Jenis APD bawaan tidak dapat dihapus. Nonaktifkan jenis APD milik organisasi Anda bila tidak dipakai.');
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
}
