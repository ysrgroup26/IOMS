<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\HseChecklistTemplate;
use App\Models\HseEquipmentType;
use App\Models\SafetyEquipment;
use App\Models\SafetyEquipmentInspection;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Milestone 4, Workstream B10. Safety Equipment Register.
 *
 * v1.11.1: `type` validates against the tenant's own configurable
 * `HseEquipmentType` codes (Rule::in over a tenant-scoped collection --
 * same IDOR-safe pattern as every other tenant-owned validation in this
 * codebase). recordInspection() writes a real SafetyEquipmentInspection
 * history row AND keeps the equipment's own last_inspection_date/
 * next_inspection_due denormalized fields in sync (dashboard widgets and
 * overdue queries read those two columns directly).
 *
 * v2.133.0 (ADR 074) -- THE REGISTER IS ITS OWN PAGE. It had been one tab
 * of the shared HSE reference-data page, listing every unit with all its
 * inspections eager-loaded and no way to search, filter, or open a unit.
 * index() and show() are the register and a unit's record; reference data
 * (types, templates, hazard categories, supplies) stays on Hse/Master.
 * The fields v2.53.0 added to the table (equipment_type_id, the unit's
 * code, brand, model, the per-lifecycle dates) are now accepted here --
 * until this release no form could write them.
 */
class SafetyEquipmentController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $companyIds = Company::query()->pluck('id');
        $today = now()->startOfDay();

        $units = SafetyEquipment::whereIn('company_id', $companyIds)
            ->with(['equipmentType', 'latestInspection', 'company:id,name'])
            ->orderBy('name')
            ->get();

        $rows = $units->map(fn (SafetyEquipment $e) => $this->row($e, $today));

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'type' => (string) $request->query('type', ''),
            'state' => (string) $request->query('state', ''),
            'location' => (string) $request->query('location', ''),
        ];

        $filtered = $rows
            ->when($filters['q'] !== '', function (Collection $c) use ($filters) {
                $needle = mb_strtolower($filters['q']);
                // APAR-01 also finds a unit still labelled APAR-00001.
                $codeKey = preg_match('/^[A-Za-z0-9]+-\d+$/', $filters['q']) ? SafetyEquipment::codeKey($filters['q']) : null;

                return $c->filter(fn ($r) => ($codeKey !== null && SafetyEquipment::codeKey($r['equipment_code']) === $codeKey)
                    || str_contains(mb_strtolower(implode(' ', array_filter([
                        $r['equipment_code'], $r['name'], $r['serial_number'], $r['location'], $r['brand'], $r['model'],
                    ]))), $needle));
            })
            ->when($filters['type'] !== '', fn (Collection $c) => $c->filter(fn ($r) => $filters['type'] === 'unclassified'
                ? $r['type_id'] === null
                : (string) $r['type_id'] === $filters['type']))
            ->when($filters['state'] !== '', fn (Collection $c) => $c->filter(fn ($r) => $filters['state'] === 'attention'
                ? in_array($r['compliance'], ['action_required', 'expired', 'overdue'], true)
                : $r['compliance'] === $filters['state']))
            ->when($filters['location'] !== '', fn (Collection $c) => $c->filter(fn ($r) => $r['location'] === $filters['location']))
            // Worst standing first, then the soonest date: the top of the
            // register is what somebody has to act on.
            ->sortBy([
                fn ($a, $b) => array_search($a['compliance'], SafetyEquipment::COMPLIANCE_STATES) <=> array_search($b['compliance'], SafetyEquipment::COMPLIANCE_STATES),
                fn ($a, $b) => ($a['next_due']['date'] ?? '9999') <=> ($b['next_due']['date'] ?? '9999'),
            ])
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator(
            $filtered->forPage($page, self::PER_PAGE)->values(),
            $filtered->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return Inertia::render('Hse/Equipment/Index', [
            'equipment' => $paginator,
            'filters' => $filters,
            'stats' => $this->stats($rows, $companyIds, $today),
            'byType' => $rows->groupBy(fn ($r) => $r['type_name'] ?? 'Unclassified')
                ->map(fn ($g, $name) => ['name' => $name, 'count' => $g->count()])
                ->sortByDesc('count')->values(),
            'attention' => $rows->filter(fn ($r) => in_array($r['compliance'], ['action_required', 'expired', 'overdue'], true))
                ->sortBy(fn ($r) => $r['next_due']['date'] ?? '0000')->take(6)->values(),
            'upcoming' => $rows->filter(fn ($r) => $r['compliance'] === 'due_soon')
                ->sortBy(fn ($r) => $r['next_due']['date'])->take(6)->values(),
            'locations' => $rows->pluck('location')->filter()->unique()->sort()->values(),
            ...$this->formOptions($companyIds),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function show(Request $request, SafetyEquipment $safetyEquipment): Response
    {
        abort_unless(Company::query()->pluck('id')->contains($safetyEquipment->company_id), 404);
        $safetyEquipment->load(['equipmentType', 'latestInspection', 'company:id,name', 'asset:id,name,asset_code', 'inspections.inspector:id,name', 'inspections.correctiveActions.assignee:id,name']);
        $today = now()->startOfDay();
        $canManage = $request->user()->canManageHse();
        $template = HseChecklistTemplate::forEquipmentType($safetyEquipment->equipment_type_id, $safetyEquipment->company_id);
        $tenantId = app(CurrentTenant::class)->id();

        return Inertia::render('Hse/Equipment/Show', [
            'equipment' => [
                ...$this->row($safetyEquipment, $today),
                'company' => $safetyEquipment->company?->only('id', 'name'),
                'asset' => $safetyEquipment->asset?->only('id', 'name', 'asset_code'),
                'commissioned_at' => $safetyEquipment->commissioned_at?->toDateString(),
                'notes' => $safetyEquipment->notes,
                'lifecycles' => $safetyEquipment->lifecycles($today),
                'interval_months' => $safetyEquipment->equipmentType?->inspection_interval_months,
                'created_at' => $safetyEquipment->created_at?->toIso8601String(),
                'updated_at' => $safetyEquipment->updated_at?->toIso8601String(),
            ],
            'inspections' => $safetyEquipment->inspections->map(fn (SafetyEquipmentInspection $i) => [
                'id' => $i->id,
                'inspection_date' => $i->inspection_date?->toDateString(),
                'condition' => $i->condition,
                'result' => $i->result,
                'findings' => $i->findings,
                'notes' => $i->notes,
                'next_inspection_due' => $i->next_inspection_due?->toDateString(),
                'inspector' => $i->inspector?->name,
                'risk_level' => $i->risk_level,
                'checklist' => $i->checklist,
                'photos' => collect($i->photos ?? [])->map(fn ($p) => ['url' => asset('storage/'.$p['path']), 'name' => $p['name'] ?? null])->values(),
                'actions' => $i->correctiveActions->map(fn (CorrectiveAction $a) => [
                    'id' => $a->id, 'action' => $a->action, 'status' => $a->status, 'priority' => $a->priority,
                    'due_date' => $a->due_date?->toDateString(), 'assignee' => $a->assignee?->name,
                ])->values(),
            ]),
            // v2.135.0: the checklist this unit's type is inspected against
            // (null = the earlier free form), and who a follow-up can go to.
            'checklistTemplate' => $template ? ['id' => $template->id, 'name' => $template->name, 'items' => $template->items] : null,
            'assignees' => $canManage
                ? User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            ...($request->user()->canManageHse() ? $this->formOptions(Company::query()->pluck('id')) : []),
            'activities' => ActivityLog::where('subject_type', SafetyEquipment::class)
                ->where('subject_id', $safetyEquipment->id)
                ->with('user:id,name')
                ->latest()
                ->limit(50)
                ->get(),
            'can' => ['manage' => $request->user()->canManageHse()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        $data = $this->validated($request);

        $equipment = DB::transaction(function () use ($data) {
            $data['equipment_code'] = $data['equipment_code']
                ?: SafetyEquipment::nextEquipmentCode((int) $data['company_id'], $data['_prefix']);
            unset($data['_prefix']);

            return SafetyEquipment::create($data);
        });
        ActivityLog::record('created', "Safety equipment {$equipment->equipment_code} \"{$equipment->name}\" was added.", $equipment);

        return back()->with('success', "Equipment {$equipment->equipment_code} added.");
    }

    public function update(Request $request, SafetyEquipment $safetyEquipment): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($safetyEquipment->company_id), 404);
        $data = $this->validated($request, $safetyEquipment);

        DB::transaction(function () use ($data, $safetyEquipment) {
            // A unit registered before units had codes gets one the first
            // time it is saved; a code that exists is never renumbered.
            $data['equipment_code'] = $data['equipment_code']
                ?: ($safetyEquipment->equipment_code ?: SafetyEquipment::nextEquipmentCode((int) $data['company_id'], $data['_prefix']));
            unset($data['_prefix']);
            $safetyEquipment->update($data);
        });
        ActivityLog::record('updated', "Safety equipment {$safetyEquipment->equipment_code} \"{$safetyEquipment->name}\" was updated.", $safetyEquipment);

        return back()->with('success', 'Equipment updated.');
    }

    public function destroy(Request $request, SafetyEquipment $safetyEquipment): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($safetyEquipment->company_id), 404);

        $label = trim(($safetyEquipment->equipment_code ? $safetyEquipment->equipment_code.' ' : '')."\"{$safetyEquipment->name}\"");
        $safetyEquipment->delete();
        ActivityLog::record('deleted', "Safety equipment {$label} was removed.");

        return redirect()->route('safety-equipment.index')->with('success', 'Equipment removed.');
    }

    /**
     * v2.135.0 -- AN INSPECTION IS JUDGED FROM ITS CHECKLIST.
     *
     * When the unit's type has an equipment checklist, the inspector answers
     * each item and the SERVER decides the result (SafetyEquipmentInspection::
     * judge): a critical Fail is Fail, any other Fail is Needs Action, an
     * expired date or an out-of-range reading fails by itself, and Damaged is
     * never Pass. The items are filed with the answers, so a later template
     * edit cannot rewrite this inspection. Anything other than Pass needs a
     * description and a risk level; a failed item that asks for evidence
     * needs a photo; and each follow-up becomes a CorrectiveAction with an
     * owner and a due date, closed and VERIFIED through the CAPA register.
     *
     * A type with no checklist keeps the earlier form (condition, result,
     * findings). The one rule that is new for it too: an inspection that is
     * not Pass now also needs a risk level, so every finding is ranked the
     * same way whichever form recorded it.
     */
    public function recordInspection(Request $request, SafetyEquipment $safetyEquipment): RedirectResponse
    {
        abort_unless($request->user()->canManageHse(), 403);
        abort_unless(Company::query()->pluck('id')->contains($safetyEquipment->company_id), 404);

        $template = HseChecklistTemplate::forEquipmentType($safetyEquipment->equipment_type_id, $safetyEquipment->company_id);
        $tenantId = app(CurrentTenant::class)->id();
        $tenantUserIds = User::when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->pluck('id');

        $data = $request->validate([
            'inspection_date' => ['required', 'date', 'before_or_equal:today'],
            'condition' => ['required', Rule::in(SafetyEquipmentInspection::CONDITIONS)],
            'result' => [$template ? 'nullable' : 'required', Rule::in(SafetyEquipmentInspection::RESULTS)],
            'findings' => ['nullable', 'string', 'max:1000'],
            'risk_level' => ['nullable', Rule::in(SafetyEquipmentInspection::RISK_LEVELS)],
            'next_inspection_due' => ['nullable', 'date', 'after:inspection_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'checklist' => [$template ? 'required' : 'nullable', 'array'],
            'checklist.*.result' => ['nullable', Rule::in(['pass', 'fail', 'na'])],
            'checklist.*.value' => ['nullable', 'string', 'max:40'],
            'checklist.*.remark' => ['nullable', 'string', 'max:500'],
            'photos' => ['nullable', 'array', 'max:6'],
            'photos.*' => ['image', 'max:5120'],
            'actions' => ['nullable', 'array', 'max:10'],
            'actions.*.action' => ['required', 'string', 'max:500'],
            'actions.*.assigned_to' => ['nullable', Rule::in($tenantUserIds)],
            'actions.*.due_date' => ['nullable', 'date', 'after_or_equal:inspection_date'],
            'actions.*.priority' => ['nullable', Rule::in(CorrectiveAction::PRIORITIES)],
        ], [
            'photos.*.image' => 'Each photo must be an image.',
            'photos.*.max' => 'Each photo must be 5 MB or smaller.',
        ]);

        $rows = null;
        if ($template) {
            $judged = SafetyEquipmentInspection::judge(
                $template->items ?? [], $data['checklist'] ?? [], Carbon::parse($data['inspection_date'])->toDateString(), $data['condition'],
            );
            if ($judged['errors'] !== []) {
                throw ValidationException::withMessages($judged['errors']);
            }
            $rows = $judged['rows'];
            $data['result'] = $judged['result'];
        }

        $notPass = $data['result'] !== 'pass';
        $missing = [];
        if ($notPass && blank($data['findings'] ?? null)) {
            $missing['findings'] = 'Describe what was found when the result is Fail or Needs Action.';
        }
        if ($notPass && empty($data['risk_level'])) {
            $missing['risk_level'] = 'Choose the risk level of what was found.';
        }
        if ($rows && collect($rows)->contains(fn ($r) => $r['result'] === 'fail' && $r['photo_on_fail']) && empty($data['photos'])) {
            $missing['photos'] = 'A failed item needs photo evidence. Add at least one photo.';
        }
        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        // The type's declared interval proposes the next date when the
        // inspector left it blank -- a rule the company configured, not a
        // guess. With no interval, the existing date stands.
        $interval = $safetyEquipment->equipmentType?->inspection_interval_months;
        if (empty($data['next_inspection_due']) && $interval) {
            $data['next_inspection_due'] = Carbon::parse($data['inspection_date'])->addMonthsNoOverflow($interval)->toDateString();
        }

        $photos = $request->file('photos', []);
        $actions = $data['actions'] ?? [];
        unset($data['checklist'], $data['photos'], $data['actions']);
        if (! $notPass) {
            $data['risk_level'] = null;
        }

        $inspection = DB::transaction(function () use ($data, $safetyEquipment, $request, $template, $rows, $photos, $actions) {
            $inspection = $safetyEquipment->inspections()->create([
                ...$data,
                'company_id' => $safetyEquipment->company_id,
                'inspector_id' => $request->user()->id,
                'checklist_template_id' => $template?->id,
                'checklist' => $rows,
            ]);

            if ($photos !== []) {
                $inspection->update(['photos' => collect($photos)->map(fn ($file) => [
                    'path' => $file->store("uploads/safety-equipment/{$safetyEquipment->id}/inspections/{$inspection->id}", 'public'),
                    'name' => mb_substr($file->getClientOriginalName(), 0, 120),
                ])->all()]);
            }

            $priority = ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'critical' => 'high'][$data['risk_level'] ?? ''] ?? 'medium';
            foreach ($actions as $action) {
                $inspection->correctiveActions()->create([
                    'company_id' => $safetyEquipment->company_id,
                    'action' => $action['action'],
                    'assigned_to' => $action['assigned_to'] ?? null,
                    'due_date' => $action['due_date'] ?? null,
                    'priority' => $action['priority'] ?? $priority,
                    'status' => CorrectiveAction::STATUS_OPEN,
                    'created_by' => $request->user()->id,
                ]);
            }

            // Keep the denormalized fields in sync. An inspection dated
            // BEFORE the latest one on file is history being back-filled
            // and must not move the unit's current dates backwards.
            $current = $safetyEquipment->last_inspection_date;
            if (! $current || ! $current->gt(Carbon::parse($data['inspection_date']))) {
                $safetyEquipment->update([
                    'last_inspection_date' => $data['inspection_date'],
                    'next_inspection_due' => $data['next_inspection_due'] ?? $safetyEquipment->next_inspection_due,
                ]);
            }

            return $inspection;
        });

        $label = $safetyEquipment->equipment_code ?: $safetyEquipment->name;
        $result = ['pass' => 'Pass', 'fail' => 'Fail', 'needs_action' => 'Needs Action'][$data['result']];
        $followUps = count($actions) ? ' '.count($actions).' corrective action(s) raised.' : '';
        ActivityLog::record('created', "Inspection recorded for {$label}: {$result}.{$followUps}", $safetyEquipment);

        return back()->with('success', "Inspection recorded: {$result}.{$followUps}");
    }

    /**
     * What the add/edit form chooses from. Every type is sent, active or
     * not, so the register's filter can still find units of a type that
     * has since been deactivated; the form itself offers only active ones
     * (plus the unit's current type when editing).
     */
    private function formOptions($companyIds): array
    {
        return [
            'equipmentTypes' => HseEquipmentType::whereIn('company_id', $companyIds)->orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (HseEquipmentType $t) => [
                    'id' => $t->id, 'company_id' => $t->company_id, 'name' => $t->name, 'code' => $t->code,
                    'code_prefix' => $t->code_prefix, 'is_active' => $t->is_active,
                    'tracked' => $t->trackedLifecycles(), 'interval_months' => $t->inspection_interval_months,
                ]),
            'assets' => Asset::whereIn('company_id', $companyIds)->orderBy('name')->get(['id', 'name', 'asset_code']),
            'companies' => Company::active()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** The register's one validation, shared by create and edit. */
    private function validated(Request $request, ?SafetyEquipment $existing = null): array
    {
        $tenantCompanyIds = Company::query()->pluck('id');
        $types = HseEquipmentType::whereIn('company_id', $tenantCompanyIds)->get();
        // An existing unit may keep a type that has since been deactivated;
        // a new choice must be an active one.
        $allowedTypeIds = $types->filter(fn ($t) => $t->is_active || $t->id === $existing?->equipment_type_id)->pluck('id');

        $data = $request->validate([
            'company_id' => ['required', Rule::in($tenantCompanyIds)],
            'name' => ['required', 'string', 'max:255'],
            'equipment_type_id' => ['nullable', Rule::in($allowedTypeIds), 'required_without:type'],
            // Legacy free-text type code, still accepted from older
            // clients; equipment_type_id wins when both are sent.
            'type' => ['nullable', Rule::in($types->where('is_active', true)->pluck('code')), 'required_without:equipment_type_id'],
            'equipment_code' => [
                'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9][A-Za-z0-9\-\/\.]*$/',
                Rule::unique('safety_equipment', 'equipment_code')
                    ->where(fn ($q) => $q->where('company_id', $request->input('company_id')))
                    ->ignore($existing?->id),
            ],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'commissioned_at' => ['nullable', 'date'],
            'last_inspection_date' => ['nullable', 'date'],
            'next_inspection_due' => ['nullable', 'date'],
            'next_calibration_due' => ['nullable', 'date'],
            'next_service_due' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'status' => ['required', Rule::in(SafetyEquipment::STATUSES)],
            'notes' => ['nullable', 'string', 'max:1000'],
            // v1.11.3 -- optional link to the general Asset register,
            // tenant-scoped the same way as every other Rule::in() here.
            'asset_id' => ['nullable', Rule::in(Asset::whereIn('company_id', $tenantCompanyIds)->pluck('id'))],
        ], [
            'equipment_code.unique' => 'Another unit in this operating unit already uses this ID.',
            'equipment_code.regex' => 'Use letters, numbers and dashes only, for example APAR-01.',
        ]);

        $type = isset($data['equipment_type_id'])
            ? $types->firstWhere('id', (int) $data['equipment_type_id'])
            : $types->where('company_id', (int) $data['company_id'])->firstWhere('code', $data['type'])
                ?? $types->firstWhere('code', $data['type']);

        $data['equipment_type_id'] = $type?->id;
        $data['type'] = $type?->code ?? ($data['type'] ?? null);
        $data['equipment_code'] = isset($data['equipment_code']) ? strtoupper(trim($data['equipment_code'])) : null;

        // v2.135.0: the exact-string rule above cannot see that APAR-1 and
        // APAR-00001 are the same unit number. While short and long codes
        // coexist, a number is taken once per operating unit, however it is
        // padded.
        if ($data['equipment_code'] !== null) {
            $key = SafetyEquipment::codeKey($data['equipment_code']);
            $clash = SafetyEquipment::query()
                ->where('company_id', (int) $data['company_id'])
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                ->whereNotNull('equipment_code')
                ->pluck('equipment_code')
                ->first(fn ($code) => SafetyEquipment::codeKey($code) === $key);
            if ($clash !== null) {
                throw ValidationException::withMessages([
                    'equipment_code' => "This number is already used by {$clash} in this operating unit.",
                ]);
            }
        }
        $data['_prefix'] = $type?->code_prefix;

        return $data;
    }

    /** One register row: what a list, a card or a detail header needs, all derived from stored facts. */
    private function row(SafetyEquipment $e, $today): array
    {
        $latest = $e->latestInspection;

        return [
            'id' => $e->id,
            'company_id' => $e->company_id,
            'company_name' => $e->company?->name,
            'equipment_code' => $e->equipment_code,
            'name' => $e->name,
            'type_id' => $e->equipment_type_id,
            'type_code' => $e->type,
            'type_name' => $e->equipmentType?->name,
            'brand' => $e->brand,
            'model' => $e->model,
            'serial_number' => $e->serial_number,
            'location' => $e->location,
            'status' => $e->status,
            'asset_id' => $e->asset_id,
            'compliance' => $e->complianceState($today),
            'next_due' => $e->nextDue($today),
            'last_inspection_date' => $e->last_inspection_date?->toDateString(),
            'last_result' => $latest?->result,
            'next_inspection_due' => $e->next_inspection_due?->toDateString(),
            'next_calibration_due' => $e->next_calibration_due?->toDateString(),
            'next_service_due' => $e->next_service_due?->toDateString(),
            'expiry_date' => $e->expiry_date?->toDateString(),
            'commissioned_at' => $e->commissioned_at?->toDateString(),
            'notes' => $e->notes,
            'tracked' => $e->equipmentType?->trackedLifecycles() ?? array_column($e->lifecycles($today), 'key'),
        ];
    }

    /** The overview figures. Every one is a count of rows above; none is estimated. */
    private function stats(Collection $rows, $companyIds, $today): array
    {
        $by = $rows->countBy('compliance');

        return [
            'total' => $rows->count(),
            'operational' => $rows->where('status', 'active')->count(),
            'out_of_service' => $by['out_of_service'] ?? 0,
            'overdue' => ($by['overdue'] ?? 0) + ($by['expired'] ?? 0),
            'expired' => $by['expired'] ?? 0,
            'due_soon' => $by['due_soon'] ?? 0,
            'action_required' => $by['action_required'] ?? 0,
            'not_scheduled' => $by['not_scheduled'] ?? 0,
            'compliant' => $by['compliant'] ?? 0,
            'without_location' => $rows->where('status', 'active')->whereNull('location')->count(),
            'without_code' => $rows->whereNull('equipment_code')->count(),
            'unclassified' => $rows->whereNull('type_id')->count(),
            'inspections_30d' => SafetyEquipmentInspection::whereIn('company_id', $companyIds)
                ->whereDate('inspection_date', '>=', $today->copy()->subDays(30))->count(),
            'due_soon_days' => SafetyEquipment::DUE_SOON_DAYS,
        ];
    }
}
