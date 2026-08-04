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

        if ($ppeType->assignments()->exists()) {
            return back()->with('error', 'Cannot delete a PPE type that has been issued to employees.');
        }

        $name = $ppeType->name;
        $ppeType->delete();

        ActivityLog::record('deleted', "PPE type \"{$name}\" was removed.");

        return back()->with('success', 'PPE type removed.');
    }
}
