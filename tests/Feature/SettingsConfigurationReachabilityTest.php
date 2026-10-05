<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\DocumentTemplate;
use App\Models\FieldMapping;
use App\Models\MaterialRequest;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ApprovalFlowResolver;
use App\Services\FieldMappingService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.99.0 -- THE TWO ROOT CAUSES BEHIND "APPROVAL FLOW GOES BLANK".
 *
 * A customer reported that Settings' Approval Flow tab sometimes turns
 * into a white page, and that configuring a flow did nothing. Those were
 * two unrelated defects that happened to meet on the same screen, and
 * neither produced a console error or a failed request -- which is why
 * both survived so long.
 *
 * 1. TENANT-WIDE CONFIGURATION WAS UNREACHABLE. Approval Flow, Documents
 *    and Import/Export Mapping all store a null `company_id` to mean
 *    "applies to the whole tenant" -- `ApprovalFlowResolver` says so
 *    outright and queries `whereNull('company_id')` to find them. v2.62.0's
 *    `CompanyOwnedScope` then gave a null owner its safe default reading,
 *    "visible to nobody", so every row these features created vanished the
 *    instant it was saved: the success message appeared, the list stayed
 *    empty, the delete/steps endpoints answered 404 for a row that plainly
 *    existed, and the resolver's own null-company branch became dead code,
 *    silently dropping every module back to legacy single-step approval.
 *
 * 2. AN UNAVAILABLE `?tab=` LEFT THE PAGE EMPTY. The query parameter went
 *    straight into Radix's `defaultValue`, and Radix renders no panel at
 *    all when the active value matches no `Tabs.Content`. Every panel is
 *    gated by the same role check as its trigger, so a PTW-only or HSE
 *    user following a bookmarked `/settings?tab=approval-flows` link got
 *    the chrome, the tab strip, no selected tab and an empty body. A stale
 *    or misspelled value did the same.
 */
class SettingsConfigurationReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\WorkspaceSeeder::class);
        $this->seed(\Database\Seeders\ModuleSeeder::class);
    }

    private function tenantOn(string $slug = 'business', string $suffix = 'a'): Tenant
    {
        $package = Package::where('slug', $slug)->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard '.$suffix,
            'slug' => 'yard-'.$suffix.'-'.uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co '.$suffix,
            'code' => 'YR'.strtoupper($suffix),
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE,
            'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        return $this->tenant;
    }

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);
    }

    /* ==================================================================
     * 1. A TENANT-WIDE CONFIGURATION SURVIVES BEING SAVED
     * ================================================================== */

    /**
     * THE DEFECT, end to end through the real routes: create a flow the
     * way the UI creates one, then re-read the page the UI re-reads.
     * Before the fix this came back as an empty list every time.
     */
    public function test_an_approval_flow_is_still_there_after_being_created(): void
    {
        $this->tenantOn();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('settings.approval-flows.store'), [
                'module_key' => 'material_request',
                'name' => 'Two Level Approval',
            ])
            ->assertSessionHasNoErrors();

        // It is stored tenant-wide, which is what the feature means by a
        // null company: it applies to every Operating Unit.
        $this->assertDatabaseHas('approval_flows', [
            'tenant_id' => $this->tenant->id,
            'company_id' => null,
            'name' => 'Two Level Approval',
        ]);

        $this->actingAs($admin)
            ->get(route('settings.index', ['tab' => 'approval-flows']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('approvalFlows', 1)
                ->where('approvalFlows.0.name', 'Two Level Approval'));
    }

    /**
     * Route model binding runs through the same global scope, so an
     * unreachable row made its own endpoints answer 404. Both of the
     * flow's endpoints must resolve it.
     */
    public function test_a_tenant_wide_flows_own_endpoints_resolve_it(): void
    {
        $this->tenantOn();
        $admin = $this->admin();

        $flow = ApprovalFlow::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => null,
            'module_key' => 'material_request',
            'name' => 'Two Level Approval',
            'is_active' => true,
            'priority' => 0,
        ]);

        $this->actingAs($admin)
            ->put(route('settings.approval-flows.steps', $flow->id), [
                'steps' => [
                    ['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager', 'escalate_after_hours' => null, 'escalate_to_role' => null],
                    ['step_number' => 2, 'mode' => 'parallel_all', 'approver_role' => 'super_admin', 'escalate_after_hours' => 24, 'escalate_to_role' => 'hse'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $flow->steps()->count());

        $this->actingAs($admin)
            ->delete(route('settings.approval-flows.destroy', $flow->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('approval_flows', ['id' => $flow->id]);
    }

    /**
     * THE WORST CONSEQUENCE, and the one no screen would have shown:
     * `ApprovalFlowResolver`'s `whereNull('company_id')` branch was dead,
     * so a configured flow resolved to null and the module silently used
     * legacy single-step approval instead.
     */
    public function test_the_resolver_finds_a_tenant_wide_flow(): void
    {
        $this->tenantOn();
        $admin = $this->admin();

        $flow = ApprovalFlow::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => null,
            'module_key' => 'material_request',
            'name' => 'Two Level Approval',
            'is_active' => true,
            'priority' => 0,
        ]);
        $flow->steps()->create(['step_number' => 1, 'mode' => 'single', 'approver_role' => 'manager']);

        $request = MaterialRequest::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'request_number' => 'MR-RESOLVE-1',
            'requested_by' => $admin->id,
            'status' => 'draft',
            'request_date' => now()->toDateString(),
        ]);

        $resolved = app(ApprovalFlowResolver::class)->resolve('material_request', $request);

        $this->assertNotNull($resolved, 'A configured tenant-wide flow must resolve, not fall back to single-step approval.');
        $this->assertSame($flow->id, $resolved->id);
    }

    /** Documents has the identical shape, and had the identical bug. */
    public function test_a_document_template_is_still_there_after_being_created(): void
    {
        $this->tenantOn();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('settings.documents.store'), [
                'module_key' => 'material_request',
                'name' => 'Standard Letterhead',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get(route('settings.index', ['tab' => 'documents']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('documentTemplates', 1)
                ->where('documentTemplates.0.name', 'Standard Letterhead'));
    }

    /** And so does Import/Export Mapping, written through its service. */
    public function test_a_field_mapping_is_still_there_after_being_saved(): void
    {
        $this->tenantOn();
        $admin = $this->admin();
        $this->actingAs($admin);

        app(FieldMappingService::class)->upsert('employees', FieldMapping::DIRECTION_IMPORT, [
            ['field_key' => 'full_name', 'column_label' => 'Nama Lengkap', 'is_enabled' => true, 'sort_order' => 0],
        ]);

        $resolved = app(FieldMappingService::class)->resolve('employees', FieldMapping::DIRECTION_IMPORT);

        $this->assertSame(
            'Nama Lengkap',
            $resolved['full_name']['label'],
            'A saved mapping must be readable back, not silently replaced by the built-in default.'
        );
    }

    /* ==================================================================
     * ISOLATION WAS NOT TRADED AWAY TO GET THIS BACK
     * ================================================================== */

    /**
     * The whole risk of making null-company rows visible again is doing
     * it with `companyScopeAllowsGlobalRows()`, which makes them visible
     * to EVERYONE. These rows carry a tenant, so they must stay inside it.
     */
    public function test_another_tenants_tenant_wide_flow_is_not_visible(): void
    {
        // Tenant B's tenant-wide flow.
        $this->tenantOn('business', 'b');
        $otherTenantId = $this->tenant->id;
        ApprovalFlow::create([
            'tenant_id' => $otherTenantId,
            'company_id' => null,
            'module_key' => 'material_request',
            'name' => 'OTHER TENANT Flow',
            'is_active' => true,
            'priority' => 0,
        ]);

        // Tenant A must not see it, on the page or through the model.
        $this->tenantOn('business', 'a');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('settings.index', ['tab' => 'approval-flows']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('approvalFlows', 0));

        $this->assertSame(
            0,
            ApprovalFlow::where('name', 'OTHER TENANT Flow')->count(),
            'A tenant-wide row must never leak out of its own tenant.'
        );
    }

    /**
     * And with no tenant resolved at all the scope still fails closed,
     * like every other scope in this codebase.
     */
    public function test_a_tenant_wide_flow_is_invisible_with_no_resolved_tenant(): void
    {
        $this->tenantOn();
        ApprovalFlow::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => null,
            'module_key' => 'material_request',
            'name' => 'Two Level Approval',
            'is_active' => true,
            'priority' => 0,
        ]);

        app(CurrentTenant::class)->set(null);

        $this->assertSame(0, ApprovalFlow::count());
    }

    /** A company-owned flow is still restricted to its own company. */
    public function test_a_company_owned_flow_is_still_company_scoped(): void
    {
        $this->tenantOn('business', 'b');
        $otherCompanyId = $this->company->id;
        $otherTenantId = $this->tenant->id;

        ApprovalFlow::create([
            'tenant_id' => $otherTenantId,
            'company_id' => $otherCompanyId,
            'module_key' => 'material_request',
            'name' => 'OTHER COMPANY Flow',
            'is_active' => true,
            'priority' => 0,
        ]);

        $this->tenantOn('business', 'a');
        $this->admin();

        $this->assertSame(0, ApprovalFlow::where('name', 'OTHER COMPANY Flow')->count());
    }

    /* ==================================================================
     * 2. AN UNAVAILABLE TAB MUST NOT LEAVE THE PAGE EMPTY
     * ================================================================== */

    /**
     * The JSX pin. Radix needs the resolved value to match a rendered
     * `Tabs.Content`, so `availableTabs` must list exactly the panels that
     * exist -- a tab added to the strip without being added there would
     * reintroduce the blank page for whoever lands on it.
     *
     * Scanned from source for the same reason `LanguageHierarchyTest`
     * scans it: the rule lives in the markup, and a comment asking the
     * next person to remember is what failed here already.
     */
    public function test_every_settings_tab_panel_is_listed_as_available(): void
    {
        $jsx = file_get_contents(resource_path('js/Pages/Settings/Index.jsx'));

        preg_match('/const availableTabs = \[(.*?)\n    \];/s', $jsx, $listMatch);
        $this->assertNotEmpty($listMatch, 'availableTabs must exist -- it is what keeps ?tab= from blanking the page.');

        preg_match_all("/'([a-z-]+)'/", $listMatch[1], $declared);
        $declaredTabs = array_unique($declared[1]);

        preg_match_all('/<Tabs\.Content value="([a-z-]+)"/', $jsx, $panels);
        $panelTabs = array_unique($panels[1]);

        preg_match_all('/<Tabs\.Trigger value="([a-z-]+)"/', $jsx, $triggers);
        $triggerTabs = array_unique($triggers[1]);

        sort($declaredTabs);
        sort($panelTabs);
        sort($triggerTabs);

        $this->assertSame($panelTabs, $declaredTabs, 'Every Tabs.Content must be listed in availableTabs, and nothing else.');
        $this->assertSame($panelTabs, $triggerTabs, 'Every panel must have a trigger and every trigger a panel.');
    }

    /**
     * And the raw query parameter must not reach `defaultValue` again,
     * which is the exact line that produced the blank page.
     */
    public function test_the_tab_parameter_is_validated_before_it_selects_a_tab(): void
    {
        $jsx = file_get_contents(resource_path('js/Pages/Settings/Index.jsx'));

        $this->assertStringContainsString(
            'availableTabs.includes(requestedTab)',
            $jsx,
            'The requested tab must be checked against the tabs this user actually has.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/defaultValue=\{new URLSearchParams/',
            $jsx,
            'An unvalidated ?tab= in defaultValue renders no panel at all -- that is the blank page.'
        );
    }

    /**
     * The server half of the same story: a user who cannot see the
     * Approval Flow tab is not served its data either, so hiding the
     * panel is not the only thing standing between them and it.
     */
    public function test_a_ptw_only_user_is_not_served_approval_flow_data(): void
    {
        $this->tenantOn();

        $hse = User::create([
            'name' => 'Hse',
            'email' => 'hse-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'),
            'role' => 'hse',
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        ApprovalFlow::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => null,
            'module_key' => 'material_request',
            'name' => 'Two Level Approval',
            'is_active' => true,
            'priority' => 0,
        ]);

        $this->actingAs($hse)
            ->get(route('settings.index', ['tab' => 'approval-flows']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.manage_system', false));
    }
}
