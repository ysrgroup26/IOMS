<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * v2.38.0 (Master Audit) -- shared, tenant-scoped employee lookup.
 *
 * THE PROBLEM THIS SOLVES (measured, not assumed): seven controllers
 * currently preload the tenant's ENTIRE active employee directory into
 * the Inertia page payload just so a form can render a `<select>` --
 * PTW's create/edit form does it for both the PIC field and the workforce
 * picker. For an office of 40 people that is invisible. For the shipyards
 * and construction firms IOMS targets, where 1,500-3,000 workers is
 * normal, it means every PTW form ships a multi-megabyte payload and
 * renders a dropdown nobody can realistically use.
 *
 * That is one problem wearing two hats: a scalability problem (payload
 * size grows with headcount on pages that do not need it) and a UX
 * problem (an unsearchable list of thousands). Both disappear if the
 * directory is queried on demand instead of shipped up front.
 *
 * WHY A SHARED ENDPOINT rather than a PTW-specific one: the same pattern
 * appears in Manpower assignment, Leave, KPI entry, Man-Hour and PPE.
 * Solving it inside PTW would leave six copies of the problem and a
 * seventh implementation to maintain. This is deliberately generic and
 * owns no module-specific logic.
 *
 * WHY NOT GlobalSearch: that endpoint searches ACROSS entity types for
 * the omnibox (employees, projects, incidents, ...). This one answers a
 * different question -- "give me selectable employees, grouped, paged" --
 * and returning a different shape from GlobalSearch would have made both
 * harder to reason about.
 *
 * Tenant safety: scoped through `Company::query()`, which passes through
 * TenantScope, the same authority every guarded controller here uses.
 * Results are capped so this can never become an unbounded data export.
 */
class EmployeeLookupController extends Controller
{
    /** Hard ceiling per request -- a lookup, never a bulk export. */
    private const MAX_PER_PAGE = 50;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            // Explicitly-requested ids, so an already-selected employee
            // still renders with a name after a page reload even when
            // they fall outside the current search/page window.
            'ids' => ['nullable', 'array', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $tenantCompanyIds = Company::query()->pluck('id');

        $query = Employee::whereIn('company_id', $tenantCompanyIds)
            ->with('department:id,name')
            ->select(['id', 'employee_id', 'full_name', 'department_id', 'company_id']);

        if (! empty($validated['ids'])) {
            // Hydration mode: resolve specific ids (still tenant-scoped).
            // Deliberately not restricted to active employees -- a permit
            // raised last month may reference someone since deactivated,
            // and showing a blank instead of their name would misrepresent
            // an existing record.
            $employees = $query->whereIn('id', $validated['ids'])->orderBy('full_name')->get();

            return response()->json(['data' => $employees->map(fn ($e) => $this->present($e))->all()]);
        }

        $perPage = min($validated['per_page'] ?? 25, self::MAX_PER_PAGE);

        $results = $query->active()
            ->search($validated['q'] ?? null)
            ->when(
                $validated['department_id'] ?? null,
                fn ($q, $id) => $q->where('department_id', $id)
            )
            ->orderBy('full_name')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($results->items())->map(fn ($e) => $this->present($e))->all(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'total' => $results->total(),
            ],
        ]);
    }

    /**
     * One shape for every consumer. `group` is what lets a client render
     * department headings without a second request or any client-side
     * knowledge of the org structure.
     */
    private function present(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'full_name' => $employee->full_name,
            'department_id' => $employee->department_id,
            'group' => $employee->department?->name ?? 'Tanpa Departemen',
        ];
    }
}
