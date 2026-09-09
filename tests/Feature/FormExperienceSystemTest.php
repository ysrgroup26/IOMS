<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.65.0 -- THE FORM EXPERIENCE SYSTEM.
 *
 * A NOTE ON WHAT THESE TESTS ARE. This repository has no JavaScript test
 * runner and this work package was explicitly not to add dependencies, so
 * these are not React component tests -- they cannot assert that a focus
 * ring is visible or that a dropdown opens. They are two things instead,
 * and both are honest about their limits:
 *
 *   CONTRACT TESTS over the shipped source, the same technique this suite
 *   already uses for the public site's reveal system and product showcase.
 *   They pin the PROPERTIES the audit measured -- required marking, error
 *   summaries, reachable actions, unsaved-change guards -- so a later edit
 *   that quietly drops one fails here rather than in production.
 *
 *   REAL HTTP TESTS that render the converted pages through their actual
 *   routes as a real tenant user, which catches a broken import, a missing
 *   prop or a fatal render.
 *
 * Interaction behaviour (keyboard traversal in SearchableSelect, the
 * confirm dialog, focus movement from ErrorSummary) was verified in the
 * browser and is recorded in the release notes, not here.
 */
class FormExperienceSystemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every page form on the system. v2.65.0 proved it on three; v2.66.0
     * rolled it across the remaining dedicated master-data pages.
     */
    private const CONVERTED_FORMS = [
        // v2.65.0
        'resources/js/Pages/Employees/Form.jsx',
        'resources/js/Pages/Assets/Form.jsx',
        'resources/js/Pages/MaterialRequests/Form.jsx',
        // v2.66.0 -- master data rollout
        'resources/js/Pages/Vendors/Form.jsx',
        'resources/js/Pages/Projects/Form.jsx',
        'resources/js/Pages/Contractors/Form.jsx',
        'resources/js/Pages/Visitors/Form.jsx',
    ];

    /**
     * Dialog-shaped editors. They take FormField and deliberately NOT
     * FormSection/FormActions/ErrorSummary -- see the note in
     * Components/shared/form/index.js for why.
     */
    private const CONVERTED_DIALOGS = [
        'resources/js/Pages/Ppe/Master.jsx',
        'resources/js/Pages/Settings/Index.jsx',
    ];

    private function source(string $path): string
    {
        return file_get_contents(base_path($path));
    }

    /* ================================================================
     * 1. THE PRIMITIVES HONOUR THEIR CONTRACT
     * ================================================================ */

    /**
     * The audit's headline number was 0/27 forms marking a required field,
     * while the PUBLIC signup form already had a component that did. The
     * asterisk alone is not enough: colour and a glyph are not available to
     * a screen reader, so requiredness has to be programmatic too.
     */
    public function test_the_field_primitive_marks_requiredness_visibly_and_programmatically(): void
    {
        $field = $this->source('resources/js/Components/shared/form/FormField.jsx');

        $this->assertStringContainsString('aria-required', $field, 'Requiredness must be programmatic, not only an asterisk.');
        $this->assertStringContainsString('sr-only', $field, 'The asterisk needs a text equivalent.');
        $this->assertStringContainsString('aria-invalid', $field);
        $this->assertStringContainsString('aria-describedby', $field, 'An error message must be associated with its control.');
        $this->assertStringContainsString('role="alert"', $field);
        $this->assertStringContainsString('data-field', $field, 'ErrorSummary needs an anchor to scroll to.');
    }

    /** A failed submit must be announced and reachable, not merely printed somewhere below the fold. */
    public function test_the_error_summary_is_announced_and_focusable(): void
    {
        $summary = $this->source('resources/js/Components/shared/form/ErrorSummary.jsx');

        $this->assertStringContainsString('role="alert"', $summary);
        $this->assertStringContainsString('tabIndex={-1}', $summary, 'The summary must be focusable to move the viewport and the screen reader.');
        $this->assertStringContainsString('.focus(', $summary);
        $this->assertStringContainsString('scrollIntoView', $summary, 'Each entry must be able to reach its field.');
    }

    /** Sticky where the screen is small, static where it is not. */
    public function test_form_actions_are_reachable_on_a_phone_without_floating_on_desktop(): void
    {
        $actions = $this->source('resources/js/Components/shared/form/FormActions.jsx');

        $this->assertStringContainsString('sticky bottom-0', $actions);
        $this->assertStringContainsString('sm:static', $actions, 'A permanently floating bar wastes desktop space.');
        $this->assertStringContainsString('safe-area-inset-bottom', $actions, 'Must clear the home indicator.');
        $this->assertStringContainsString('destructive', $actions, 'Destructive actions must have a separated slot.');
    }

    /** Both exits, and only when the form is genuinely dirty. */
    public function test_the_unsaved_changes_guard_covers_both_exits(): void
    {
        $hook = $this->source('resources/js/lib/useUnsavedChanges.js');

        $this->assertStringContainsString('beforeunload', $hook, 'Leaving the site must be guarded.');
        $this->assertStringContainsString("router.on('before'", $hook, 'In-app navigation must be guarded.');
        $this->assertStringContainsString('release', $hook, 'Submitting must be able to disarm the guard.');
        $this->assertStringContainsString('shallowEqual', $hook, 'Dirtiness must be a real comparison, not a touched flag.');
    }

    /**
     * The three selectors must stay distinct. `Combobox` returns free text
     * and must never be used for a foreign key -- tenant isolation and
     * every report that joins on that FK depend on the value being a real
     * id, which is why SearchableSelect exists as a separate primitive.
     */
    public function test_the_searchable_select_is_id_based_and_keyboard_operable(): void
    {
        $select = $this->source('resources/js/Components/shared/form/SearchableSelect.jsx');

        $this->assertStringContainsString('role="listbox"', $select);
        $this->assertStringContainsString('aria-activedescendant', $select);
        $this->assertStringContainsString("case 'ArrowDown'", $select);
        $this->assertStringContainsString("case 'Escape'", $select);
        $this->assertStringContainsString('searchThreshold', $select, 'A search box over six options is furniture.');

        $combobox = $this->source('resources/js/Components/shared/Combobox.jsx');
        $this->assertStringContainsString('free text', $combobox, 'Combobox must remain the free-text primitive, not an FK picker.');
    }

    /* ================================================================
     * 2. THE CONVERTED FORMS ADOPTED IT
     * ================================================================ */

    public function test_every_converted_form_adopts_the_system(): void
    {
        foreach (self::CONVERTED_FORMS as $path) {
            $source = $this->source($path);

            $this->assertStringContainsString('@/Components/shared/form', $source, "$path does not use the form system.");
            $this->assertStringContainsString('<ErrorSummary', $source, "$path has no error summary.");
            $this->assertStringContainsString('<FormActions', $source, "$path has no reachable action bar.");
            $this->assertStringContainsString('<FormSection', $source, "$path is not grouped into sections.");
            $this->assertStringContainsString('useUnsavedChanges', $source, "$path does not protect unsaved work.");
            $this->assertMatchesRegularExpression('/required\b/', $source, "$path marks no field as required.");
        }
    }

    /**
     * The ad-hoc pattern this replaces. Its real fault was not ugliness: a
     * field could be written WITHOUT it, and in Assets/Form thirteen of
     * fifteen fields were -- so those validation errors reached the browser
     * and were silently discarded. FormField cannot be declared without an
     * error slot, and this stops the old shape creeping back.
     */
    public function test_no_converted_form_still_hand_rolls_its_error_markup(): void
    {
        foreach (self::CONVERTED_FORMS as $path) {
            $this->assertStringNotContainsString(
                'text-xs text-red-600',
                $this->source($path),
                "$path still hand-rolls error markup, which is how a field ends up with no error slot at all."
            );
        }
    }

    /**
     * Two of the three cascading selects on the Employee form used to grey
     * out with no explanation. A disabled control that does not say why
     * reads as broken.
     */
    public function test_dependent_fields_explain_their_dependency(): void
    {
        $employee = $this->source('resources/js/Pages/Employees/Form.jsx');

        $this->assertStringContainsString('Choose an Operating Unit first', $employee);
        $this->assertStringContainsString('Choose a Department first', $employee);
    }

    /** A workflow form must say what submitting does. */
    public function test_the_workflow_form_states_what_happens_on_submit(): void
    {
        $this->assertStringContainsString(
            'Submitting sends this for approval',
            $this->source('resources/js/Pages/MaterialRequests/Form.jsx')
        );
    }

    /* ================================================================
     * 3. THE PAGES STILL RENDER, THROUGH THEIR REAL ROUTES
     * ================================================================ */

    public function test_the_converted_pages_render_for_a_real_tenant_user(): void
    {
        $admin = $this->tenantAdmin();

        foreach ([
            'employees.create' => 'Employees/Form',
            'assets.create' => 'Assets/Form',
            'material-requests.create' => 'MaterialRequests/Form',
            'vendors.create' => 'Vendors/Form',
            'projects.create' => 'Projects/Form',
            'contractors.create' => 'Contractors/Form',
            'visitors.create' => 'Visitors/Form',
        ] as $routeName => $component) {
            $this->actingAs($admin)
                ->get(route($routeName))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component($component));
        }
    }

    /**
     * The Asset form's responsible-employee picker moved to the shared
     * EmployeeSelector, which queries on demand. The page must therefore
     * STOP shipping the whole employee directory -- that payload was the
     * exact cost EmployeeSelector was introduced to remove in v2.38.0, and
     * leaving it would mean paying it for a control that no longer reads it.
     */
    public function test_the_asset_form_no_longer_ships_the_employee_directory(): void
    {
        $admin = $this->tenantAdmin();

        $props = $this->actingAs($admin)->get(route('assets.create'))->viewData('page')['props'];

        $this->assertArrayNotHasKey('employees', $props);
        $this->assertArrayHasKey('companies', $props, 'The props it does still need must survive.');
        $this->assertArrayHasKey('categories', $props);
    }

    /**
     * ErrorSummary renders Laravel's own validation bag, so the bag has to
     * arrive. This is the half of the summary that can be tested without a
     * browser: submit nothing, and confirm the server rejects it and hands
     * back per-field messages keyed the way `FormField`'s `data-field`
     * anchors expect.
     *
     * It also pins that the UI change did NOT relax the server. The whole
     * point of the redesign was to make requiredness visible; if making it
     * visible had also made it optional, that would be worse than the
     * original problem.
     */
    public function test_the_server_still_rejects_an_empty_submission_and_names_the_fields(): void
    {
        $admin = $this->tenantAdmin();

        $response = $this->actingAs($admin)
            ->from(route('employees.create'))
            ->post(route('employees.store'), []);

        $response->assertSessionHasErrors();

        $errors = session('errors')->getBag('default')->keys();

        // The fields the form marks with an asterisk are the fields the
        // server actually enforces -- the marking is not decorative.
        foreach (['employee_id', 'full_name', 'company_id', 'department_id', 'status', 'employment_type'] as $field) {
            $this->assertContains($field, $errors, "The server no longer enforces {$field}, which the form marks as required.");
        }
    }

    /**
     * The same alignment check for the Asset form.
     *
     * Worth having twice, because writing these tests is what caught the
     * mistake: the first draft of the Employee form marked `position_id`
     * and `join_date` required and the Asset form marked `category`
     * required, when all three are `nullable` server-side. A form that
     * demands more than the server does is not a safe error to make -- it
     * blocks work the system would have accepted.
     */
    public function test_the_asset_form_marks_only_what_the_server_enforces(): void
    {
        $admin = $this->tenantAdmin();

        $response = $this->actingAs($admin)
            ->from(route('assets.create'))
            ->post(route('assets.store'), []);

        $response->assertSessionHasErrors(['name', 'company_id']);

        $errors = session('errors')->getBag('default')->keys();

        foreach (['category', 'serial_number', 'brand', 'model', 'location', 'notes'] as $optional) {
            $this->assertNotContains(
                $optional,
                $errors,
                "The Asset form treats {$optional} as optional; the server now disagrees."
            );
        }
    }

    /**
     * v2.66.0 -- the dialog rule, pinned.
     *
     * The rollout hit a real limit of the v2.65.0 system: FormActions is a
     * sticky page footer and DialogFooter is already a dialog's action
     * area, so putting both in one modal gives it two. And an ErrorSummary
     * above five fields that are all on screen restates what the reader can
     * already see. The answer was NOT a second set of components -- it was
     * to use only the part that generalises, which is the field contract.
     */
    public function test_dialog_editors_take_the_field_contract_but_not_the_page_furniture(): void
    {
        foreach (self::CONVERTED_DIALOGS as $path) {
            $source = $this->source($path);

            $this->assertStringContainsString('<FormField', $source, "$path does not use the field contract.");
        }

        $ppe = $this->source('resources/js/Pages/Ppe/Master.jsx');
        $this->assertStringNotContainsString('<FormActions', $ppe, 'A dialog must not carry a second action bar beside DialogFooter.');
        $this->assertStringNotContainsString('<ErrorSummary', $ppe, 'A dialog small enough to see whole does not need a summary.');
        $this->assertStringContainsString('DialogFooter', $ppe);
    }

    /**
     * Visitor registration happens at a gatehouse with somebody waiting, so
     * the host picker moved to EmployeeSelector and the page stopped
     * shipping the directory -- the same fix Assets got in v2.65.0. The
     * server still validates the submitted id against the tenant's own
     * employees; only the payload changed.
     */
    public function test_the_visitor_form_no_longer_ships_the_employee_directory(): void
    {
        $admin = $this->tenantAdmin();

        $props = $this->actingAs($admin)->get(route('visitors.create'))->viewData('page')['props'];

        $this->assertArrayNotHasKey('employees', $props);
        $this->assertArrayHasKey('visitorNumber', $props);
    }

    /** An Administrator of a real tenant, provisioned the way the app does it. */
    private function tenantAdmin(): User
    {
        $tenant = Tenant::create([
            'name' => 'Form Test Co', 'slug' => 'form-test-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $company = Company::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'name' => 'Form Test Yard', 'code' => 'FTY', 'is_active' => true,
        ]);

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'form-'.uniqid().'@example.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'is_active' => true,
        ]);

        app(CurrentTenant::class)->set($tenant);

        $department = Department::create(['company_id' => $company->id, 'name' => 'Fabrication', 'is_active' => true]);
        $position = Position::create([
            'company_id' => $company->id, 'department_id' => $department->id, 'name' => 'Fitter', 'is_active' => true,
        ]);
        Employee::create([
            'company_id' => $company->id, 'department_id' => $department->id, 'position_id' => $position->id,
            'employee_id' => 'FTY-0001', 'full_name' => 'Test Worker', 'status' => 'active',
        ]);

        return $admin;
    }
}
