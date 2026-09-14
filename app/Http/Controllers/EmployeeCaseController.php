<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeCaseActionRequest;
use App\Http\Requests\StoreEmployeeCaseRequest;
use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\EmployeeCase;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * v2.69.0 -- Employee Cases.
 *
 * AUTHORIZATION IS THE UNUSUAL PART OF THIS CONTROLLER, so it is stated
 * once here rather than re-explained per method. Every action requires
 * `canManageEmployeeCases()` (HR or Company Admin), which is deliberately
 * narrower than the rest of the HR workspace -- see that method's own doc
 * comment. There is no "read-only for managers" tier: a disciplinary
 * record is not a thing seniority entitles someone to browse.
 *
 * Company ownership comes from `BelongsToCompany` on the model, so no
 * query here can reach another tenant's cases. `assertInCurrentTenant()`
 * restates the boundary on route-bound records anyway, matching the
 * belt-and-braces precedent every other controller in this codebase
 * follows (VisitorController, AssetController, MaterialRequestController).
 */
class EmployeeCaseController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeModule($request);

        $cases = EmployeeCase::query()
            ->with('employee:id,employee_id,full_name,department_id', 'employee.department:id,name', 'assignee:id,name')
            ->withCount('actions')
            ->when($request->input('search'), function ($q, $v) {
                $q->where(function ($inner) use ($v) {
                    $inner->where('case_number', 'like', "%{$v}%")
                        ->orWhere('title', 'like', "%{$v}%")
                        ->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$v}%")->orWhere('employee_id', 'like', "%{$v}%"));
                });
            })
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->input('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->input('severity'), fn ($q, $v) => $q->where('severity', $v))
            ->when($request->boolean('active'), fn ($q) => $q->active())
            ->latest('reported_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('EmployeeCases/Index', [
            'cases' => $cases,
            'filters' => $request->only('search', 'status', 'category', 'severity', 'active'),
            'summary' => [
                'active' => EmployeeCase::query()->active()->count(),
                'open' => EmployeeCase::query()->where('status', EmployeeCase::STATUS_OPEN)->count(),
            ],
            'options' => $this->options(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeModule($request);

        return Inertia::render('EmployeeCases/Form', [
            'employeeCase' => null,
            'caseNumber' => EmployeeCase::generateCaseNumber(),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'handlers' => $this->handlers(),
            'options' => $this->options(),
        ]);
    }

    public function store(StoreEmployeeCaseRequest $request): RedirectResponse
    {
        $case = DB::transaction(function () use ($request) {
            $case = EmployeeCase::create([
                ...$request->validated(),
                'case_number' => EmployeeCase::generateCaseNumber($request->validated()['company_id']),
                'status' => EmployeeCase::STATUS_OPEN,
                'reported_by' => $request->user()->id,
            ]);

            ActivityLog::record('created', "Opened Employee Case {$case->case_number}.", $case);

            return $case;
        });

        return redirect()->route('employee-cases.show', $case)->with('flash', ['success' => 'Employee case opened.']);
    }

    public function show(EmployeeCase $employeeCase, Request $request): Response
    {
        $this->authorizeModule($request);
        $this->assertInCurrentTenant($employeeCase);

        $employeeCase->load(
            'employee:id,employee_id,full_name,department_id,position_id,company_id',
            'employee.department:id,name',
            'employee.position:id,name',
            'actions.issuer:id,name',
            'reporter:id,name',
            'assignee:id,name',
            'closer:id,name',
        );

        $activities = ActivityLog::where('subject_type', EmployeeCase::class)
            ->where('subject_id', $employeeCase->id)
            ->with('user:id,name')
            ->latest()
            ->get();

        return Inertia::render('EmployeeCases/Show', [
            'employeeCase' => $employeeCase,
            'activities' => $activities,
            // The person's standing across EVERY case, not just this one --
            // an escalation decision is read against their whole record.
            'standing' => $employeeCase->employee?->currentDisciplinaryStanding(),
            'priorCaseCount' => EmployeeCase::query()
                ->where('employee_id', $employeeCase->employee_id)
                ->where('id', '!=', $employeeCase->id)
                ->count(),
            'options' => $this->options(),
            'canOverride' => $request->user()->isSuperAdmin() || in_array($request->user()->role, config('workflow.overriders', []), true),
        ]);
    }

    public function edit(EmployeeCase $employeeCase, Request $request): Response
    {
        $this->authorizeModule($request);
        $this->assertInCurrentTenant($employeeCase);

        // A concluded case is a record of what was decided. Editing its
        // substance afterwards would make the record unreliable, which is
        // the one thing it cannot be.
        abort_unless($employeeCase->is_active, 422);

        return Inertia::render('EmployeeCases/Form', [
            'employeeCase' => $employeeCase->load('employee:id,employee_id,full_name'),
            'caseNumber' => $employeeCase->case_number,
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
            'handlers' => $this->handlers(),
            'options' => $this->options(),
        ]);
    }

    public function update(StoreEmployeeCaseRequest $request, EmployeeCase $employeeCase): RedirectResponse
    {
        $this->assertInCurrentTenant($employeeCase);
        abort_unless($employeeCase->is_active, 422);

        $employeeCase->update($request->validated());
        ActivityLog::record('updated', "Updated Employee Case {$employeeCase->case_number}.", $employeeCase);

        return redirect()->route('employee-cases.show', $employeeCase)->with('flash', ['success' => 'Employee case updated.']);
    }

    /** HR picks the case up. */
    public function startReview(Request $request, EmployeeCase $employeeCase): RedirectResponse
    {
        $this->authorizeModule($request);
        $this->assertInCurrentTenant($employeeCase);

        return $this->transition($employeeCase, EmployeeCase::STATUS_UNDER_REVIEW, $request, 'Review started.');
    }

    /**
     * Issue a disciplinary action, and move the case to `action_issued` in
     * the same transaction -- the status is a CONSEQUENCE of the action
     * existing, never something set independently of it. That is what
     * stops the two disagreeing.
     */
    public function storeAction(StoreEmployeeCaseActionRequest $request, EmployeeCase $employeeCase): RedirectResponse
    {
        $this->assertInCurrentTenant($employeeCase);

        try {
            DB::transaction(function () use ($employeeCase, $request) {
                $action = $employeeCase->actions()->create([
                    ...$request->validated(),
                    'issued_by' => $request->user()->id,
                ]);

                ActivityLog::record(
                    'action_issued',
                    'Issued '.str_replace('_', ' ', $action->type)." on case {$employeeCase->case_number}.",
                    $employeeCase,
                    ['type' => $action->type, 'effective_until' => $action->effective_until?->toDateString()],
                );

                // Already at action_issued when a second action is added to
                // the same case, which is legitimate -- the guard says so,
                // so this asks rather than assumes.
                if ($employeeCase->canTransitionTo(EmployeeCase::STATUS_ACTION_ISSUED)) {
                    $employeeCase->transitionTo(EmployeeCase::STATUS_ACTION_ISSUED, $request->user());
                }
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => 'Disciplinary action recorded.']);
    }

    /**
     * The employee was served the letter. A separate fact from issuing it,
     * and the one an employment dispute turns on.
     */
    public function acknowledgeAction(Request $request, EmployeeCase $employeeCase, int $action): RedirectResponse
    {
        $this->authorizeModule($request);
        $this->assertInCurrentTenant($employeeCase);

        $record = $employeeCase->actions()->findOrFail($action);
        $record->update(['acknowledged_at' => now()]);

        ActivityLog::record(
            'acknowledged',
            'Recorded employee acknowledgement of '.str_replace('_', ' ', $record->type).'.',
            $employeeCase,
        );

        return back()->with('flash', ['success' => 'Acknowledgement recorded.']);
    }

    public function close(Request $request, EmployeeCase $employeeCase): RedirectResponse
    {
        $this->authorizeModule($request);
        $this->assertInCurrentTenant($employeeCase);

        $data = $request->validate([
            'outcome' => ['required', Rule::in(EmployeeCase::OUTCOMES)],
            'closure_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->transition(
            $employeeCase,
            EmployeeCase::STATUS_CLOSED,
            $request,
            'Case closed.',
            [
                'outcome' => $data['outcome'],
                'closure_note' => $data['closure_note'] ?? null,
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
            ],
        );
    }

    /** Reviewed, nothing to answer. Distinct from closing a real case. */
    public function dismiss(Request $request, EmployeeCase $employeeCase): RedirectResponse
    {
        $this->authorizeModule($request);
        $this->assertInCurrentTenant($employeeCase);

        $data = $request->validate(['closure_note' => ['required', 'string', 'max:2000']], [], ['closure_note' => 'reason']);

        return $this->transition(
            $employeeCase,
            EmployeeCase::STATUS_DISMISSED,
            $request,
            'Case dismissed.',
            [
                'outcome' => 'unsubstantiated',
                'closure_note' => $data['closure_note'],
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
            ],
        );
    }

    /**
     * Override only, matching MaterialRequest's rejected -> draft
     * precedent: reopening a concluded case about a person is not a
     * routine correction.
     */
    public function reopen(Request $request, EmployeeCase $employeeCase): RedirectResponse
    {
        $this->assertInCurrentTenant($employeeCase);
        $allowed = config('workflow.overriders', []);
        abort_unless($request->user()->isSuperAdmin() || in_array($request->user()->role, $allowed, true), 403);

        return $this->transition(
            $employeeCase,
            EmployeeCase::STATUS_UNDER_REVIEW,
            $request,
            'Case reopened by override.',
            ['outcome' => null, 'closed_by' => null, 'closed_at' => null],
        );
    }

    private function transition(EmployeeCase $case, string $status, Request $request, string $message, array $extra = []): RedirectResponse
    {
        try {
            DB::transaction(function () use ($case, $status, $request, $extra) {
                $case->transitionTo($status, $request->user());

                if ($extra !== []) {
                    $case->update($extra);
                }
            });
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('flash', ['success' => $message]);
    }

    private function authorizeModule(Request $request): void
    {
        abort_unless($request->user()->canManageEmployeeCases(), 403);
    }

    private function assertInCurrentTenant(EmployeeCase $case): void
    {
        abort_unless(Company::query()->pluck('id')->contains($case->company_id), 404);
    }

    /**
     * Who a case can be handed to. A plain list rather than a searching
     * picker because a tenant has tens of USERS, not thousands -- unlike
     * the employee directory, which is why the SUBJECT of a case is chosen
     * with EmployeeSelector and the handler is not.
     *
     * `User` carries UserTenantScope, so this cannot list another
     * customer's staff; StoreEmployeeCaseRequest re-checks the id anyway.
     */
    private function handlers()
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

    /** The vocabulary the forms and filters render, from one place. */
    private function options(): array
    {
        return [
            'categories' => EmployeeCase::CATEGORIES,
            'severities' => EmployeeCase::SEVERITIES,
            'statuses' => [
                EmployeeCase::STATUS_OPEN,
                EmployeeCase::STATUS_UNDER_REVIEW,
                EmployeeCase::STATUS_ACTION_ISSUED,
                EmployeeCase::STATUS_CLOSED,
                EmployeeCase::STATUS_DISMISSED,
            ],
            'outcomes' => EmployeeCase::OUTCOMES,
            'actionTypes' => \App\Models\EmployeeCaseAction::TYPES,
        ];
    }
}
