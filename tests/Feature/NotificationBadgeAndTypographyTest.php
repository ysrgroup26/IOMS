<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\Package;
use App\Models\Position;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use App\Support\IdentifierCase;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.130.0 -- THE BADGE, THE IDENTIFIERS, AND THE TYPE.
 *
 * Three of this release's six areas have an assertable core, and this is
 * it. The reported defect -- "the badge still shows 2 after the
 * notifications have been opened" -- was not a styling problem and not a
 * sync problem: the badge was counting something that cannot be read.
 */
class NotificationBadgeAndTypographyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'badge-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Unit', 'code' => 'BU', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id'));
        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'badge-'.uniqid().'@badge.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'is_active' => true, 'email_verified_at' => now(),
        ]);
    }

    private function notify(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Notification::create([
                'user_id' => $this->admin->id,
                'category' => Notification::CATEGORY_APPROVAL,
                'title' => 'Something happened '.$i,
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 1. The badge counts unread notifications, and nothing else
    |--------------------------------------------------------------------------
    */

    /**
     * THE DEFECT ITSELF. `ppe_alert_count` is a live computed count of PPE
     * items expiring or expired -- it cannot be read, it does not belong to
     * anybody, and it was being ADDED to the unread count to make the
     * badge. A tenant with two expiring items therefore had a bell showing
     * 2 forever, and reading every notification changed nothing.
     */
    public function test_the_bell_badge_is_the_unread_count_alone(): void
    {
        $layout = (string) file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

        $this->assertStringContainsString('const badgeCount = unreadCount;', $layout);
        // The assignment, not the prose: the comment above it explains the
        // defect and necessarily quotes the expression it replaced.
        $this->assertStringNotContainsString('const badgeCount = unreadCount + ppeAlertCount', $layout,
            'A PPE alert is not a notification and cannot be read; adding it to the badge is what made the badge meaningless.');
    }

    public function test_two_unread_notifications_report_two_and_opening_them_counts_down(): void
    {
        $this->notify(2);

        $this->assertSame(2, $this->live()->json('unread_count'));

        $first = Notification::where('user_id', $this->admin->id)->orderBy('id')->first();
        $this->actingAs($this->admin)->postJson('/notifications/'.$first->id.'/read')->assertOk();

        $this->assertSame(1, $this->live()->json('unread_count'));

        $second = Notification::where('user_id', $this->admin->id)->orderByDesc('id')->first();
        $this->actingAs($this->admin)->postJson('/notifications/'.$second->id.'/read')->assertOk();

        $this->assertSame(0, $this->live()->json('unread_count'));
    }

    /** Reloading, navigating or reconnecting must not resurrect the count. */
    public function test_a_read_notification_never_returns_to_unread(): void
    {
        $this->notify(2);

        Notification::where('user_id', $this->admin->id)->each(function (Notification $n) {
            $this->actingAs($this->admin)->postJson('/notifications/'.$n->id.'/read')->assertOk();
        });

        // A reconnect (no cursor), a poll (with one), and a fresh page load
        // all have to agree.
        $this->assertSame(0, $this->live()->json('unread_count'));
        $this->assertSame(0, $this->live(['cursor' => 0])->json('unread_count'));

        $page = $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        $this->assertSame(0, $page->viewData('page')['props']['notifications']['unread_count']);
    }

    /**
     * v2.130.0 -- the ids a tab needs to grey out a row somebody read on
     * another device. The count alone cannot do it: the badge was always
     * the server's number, but the ROW kept its unread treatment.
     */
    public function test_the_feed_reports_which_rows_are_still_unread(): void
    {
        $this->notify(2);

        $ids = Notification::where('user_id', $this->admin->id)->pluck('id')->all();

        $this->assertEqualsCanonicalizing($ids, $this->live()->json('unread_ids'));

        $this->actingAs($this->admin)->postJson('/notifications/'.$ids[0].'/read')->assertOk();

        $remaining = $this->live()->json('unread_ids');

        $this->assertNotContains($ids[0], $remaining, 'A row read elsewhere must stop being reported as unread.');
        $this->assertContains($ids[1], $remaining);
    }

    public function test_one_persons_read_does_not_touch_anothers(): void
    {
        $other = User::create([
            'name' => 'Other', 'email' => 'other-'.uniqid().'@badge.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_HSE,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id, 'is_active' => true,
        ]);

        $this->notify(1);
        Notification::create([
            'user_id' => $other->id, 'category' => Notification::CATEGORY_APPROVAL, 'title' => 'Theirs',
        ]);

        $mine = Notification::where('user_id', $this->admin->id)->sole();
        $this->actingAs($this->admin)->postJson('/notifications/'.$mine->id.'/read')->assertOk();

        $this->assertSame(1, $this->actingAs($other)->getJson('/notifications/live')->json('unread_count'));
    }

    private function live(array $query = [])
    {
        return $this->actingAs($this->admin)->getJson('/notifications/live?'.http_build_query($query));
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Live delivery is not a dashboard feature
    |--------------------------------------------------------------------------
    */

    public function test_the_live_configuration_reaches_every_authenticated_page(): void
    {
        foreach (['/dashboard', '/permits-to-work', '/employees'] as $path) {
            $props = $this->actingAs($this->admin)->get($path)->assertOk()->viewData('page')['props'];

            $this->assertSame('sync', $props['realtime']['transport'] ?? null,
                "Live notifications stop being delivered on {$path}.");
        }
    }

    public function test_a_guest_is_never_told_to_poll(): void
    {
        $props = $this->get('/login')->assertOk()->viewData('page')['props'];

        $this->assertSame('none', $props['realtime']['transport'] ?? null);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Push does not duplicate what the open page already showed
    |--------------------------------------------------------------------------
    */

    public function test_the_service_worker_stands_down_when_a_window_is_visible(): void
    {
        $worker = (string) file_get_contents(public_path('service-worker.js'));

        $this->assertStringContainsString("visibilityState === 'visible'", $worker,
            'A person looking at IOMS has already been told; a system notification as well is the same event twice.');
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Identifiers are upper case; everything else is the person's to choose
    |--------------------------------------------------------------------------
    */

    public function test_an_identifier_is_upper_cased_and_trimmed(): void
    {
        $this->assertSame('EMP-0001', IdentifierCase::upper('  emp-0001 '));
        $this->assertSame('GAJ', IdentifierCase::upper('gaj'));
    }

    public function test_a_blank_identifier_stays_blank_rather_than_becoming_a_value(): void
    {
        $this->assertSame('', IdentifierCase::upper(''));
        $this->assertNull(IdentifierCase::upper(null));
        // An optional code left empty must not become a value a unique
        // index then has to be told about.
        $this->assertSame('   ', IdentifierCase::upper('   '));
    }

    public function test_a_badge_number_is_stored_in_the_case_it_is_read_in(): void
    {
        $department = Department::create(['company_id' => $this->company->id, 'name' => 'HSE']);
        $position = Position::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'name' => 'Welder', 'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post('/employees', [
            'employee_id' => 'emp-0042',
            'full_name' => 'budi santoso',
            'company_id' => $this->company->id,
            'department_id' => $department->id,
            'position_id' => $position->id,
            'join_date' => '2026-01-05',
            'status' => 'active',
            'employment_type' => Employee::EMPLOYMENT_TYPE_PKWTT,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $employee = Employee::withoutGlobalScopes()->where('employee_id', 'EMP-0042')->sole();

        $this->assertSame('EMP-0042', $employee->employee_id);
        // AND THE EXCLUSION, which matters as much: a person's name is
        // theirs, and nothing here touches it.
        $this->assertSame('budi santoso', $employee->full_name);
    }

    public function test_an_operating_unit_code_is_upper_cased_on_the_server(): void
    {
        $this->actingAs($this->admin)->post('/settings/companies', [
            'name' => 'Yard Two',
            'code' => 'yd2',
        ])->assertRedirect();

        $this->assertDatabaseHas('companies', ['name' => 'Yard Two', 'code' => 'YD2']);
    }

    public function test_a_department_code_is_upper_cased_on_the_server(): void
    {
        $this->actingAs($this->admin)->post('/settings/departments', [
            'company_id' => $this->company->id,
            'name' => 'Fabrication',
            'code' => 'fab',
        ])->assertRedirect();

        // The NAME is upper-cased by `NameFormatter::department()`, which
        // predates this release and is a separate, deliberate rule; what
        // this test pins is the CODE.
        $this->assertDatabaseHas('departments', ['code' => 'FAB']);
    }

    public function test_a_warehouse_code_is_upper_cased_on_the_server(): void
    {
        $this->actingAs($this->admin)->post('/warehouses', [
            'company_id' => $this->company->id,
            'code' => 'wh-a',
            'name' => 'Main Store',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('warehouses', ['name' => 'Main Store', 'code' => 'WH-A']);
    }

    /**
     * THE EXCLUSIONS ARE AS DELIBERATE AS THE INCLUSIONS. A machine key is
     * a key, and the product must not quietly change one.
     */
    public function test_a_machine_key_is_not_an_identifier_and_stays_as_typed(): void
    {
        $master = (string) file_get_contents(app_path('Http/Controllers/WasteMasterController.php'));

        // The waste TYPE's code is `used_oil`; only the storage point's
        // code ("TPS-A") is normalised.
        $this->assertSame(1, substr_count($master, 'IdentifierCase::in'),
            'Exactly one of the two code fields in this controller is an identifier.');

        $jsx = (string) file_get_contents(resource_path('js/Pages/Hse/WasteMaster.jsx'));

        $this->assertStringContainsString('placeholder="e.g. used_oil"', $jsx);
        $this->assertStringNotContainsString('placeholder="e.g. used_oil" uppercase', $jsx);
        // Nor the regulatory waste code, which is `B105d` by law.
        $this->assertStringNotContainsString('placeholder="e.g. B105d" uppercase', $jsx);
    }

    public function test_nothing_uppercases_a_name_an_email_a_password_or_an_address(): void
    {
        foreach ([
            'js/Pages/Employees/Form.jsx' => ['full_name', 'email', 'address'],
            'js/Pages/Auth/Login.jsx' => ['email', 'password'],
        ] as $file => $fields) {
            $source = (string) file_get_contents(resource_path($file));

            foreach ($fields as $field) {
                $this->assertDoesNotMatchRegularExpression(
                    "/setData\\('{$field}'[^)]*\\)[^>]{0,200}uppercase/s",
                    $source,
                    "The {$field} field in {$file} must keep the case the person typed.",
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 6. The typography reaches the PDF, not only the browser
    |--------------------------------------------------------------------------
    */

    public function test_the_document_typefaces_ship_with_the_repository(): void
    {
        foreach (['Archivo-Bold.ttf', 'Archivo-ExtraBold.ttf', 'ArchivoCondensed-ExtraBold.ttf'] as $font) {
            $path = resource_path('fonts/'.$font);

            $this->assertFileExists($path, 'The PDF cannot reach a webfont CDN; the faces must be in the repository.');
            $this->assertGreaterThan(20000, filesize($path), 'A truncated font renders as the fallback and nothing says so.');
        }

        $this->assertFileExists(resource_path('fonts/OFL.txt'), 'The licence travels with the fonts.');
    }

    public function test_the_permit_pdf_declares_the_embedded_faces(): void
    {
        $blade = (string) file_get_contents(resource_path('views/pdf/permit-to-work.blade.php'));

        $this->assertStringContainsString("@include('pdf.partials.fonts')", $blade);
        $this->assertStringContainsString('"IOMS Title"', $blade, 'The permit type band is the condensed face.');
        $this->assertStringContainsString('"IOMS Display"', $blade);

        $fonts = (string) file_get_contents(resource_path('views/pdf/partials/fonts.blade.php'));

        $this->assertStringContainsString('ArchivoCondensed-ExtraBold.ttf', $fonts);
        // A webfont URL here would make the renderer's output depend on the
        // network, which is how one deploy prints a different document.
        $this->assertStringNotContainsString('https://', $fonts);
    }

    public function test_the_screen_and_the_paper_use_the_same_display_family(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('.type-document-title', $css);
        $this->assertStringContainsString('font-stretch: 75%', $css,
            'The document title takes its authority from the width axis, not from size.');

        $blade = (string) file_get_contents(resource_path('views/app.blade.php'));

        $this->assertStringContainsString('Archivo:wdth,wght@75..100,400..900', $blade,
            'The browser must load the same axes the roles use.');
    }

    /**
     * A title set at 600 is neither regular nor bold. ADR 079 (v2.136.0)
     * moved the pair from 800 to 700: the owner asked for type that is
     * "elegant rather than heavy", and Manrope at 800 is the weight that
     * reads as shouting. 600 is still the weight nobody may use here.
     */
    public function test_a_page_title_is_genuinely_bold(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.type-page-title \{[^}]*font-bold/s', $css);
        $this->assertMatchesRegularExpression('/\.type-metric \{[^}]*font-bold/s', $css);
        $this->assertDoesNotMatchRegularExpression('/\.type-(page-title|metric) \{[^}]*font-semibold/s', $css);

        $header = (string) file_get_contents(resource_path('js/Components/shared/PageHeader.jsx'));

        $this->assertStringContainsString('type-page-title', $header);
        $this->assertStringNotContainsString('text-[22px] font-semibold', $header,
            'A page title at 600 is the weight this release exists to stop using.');
    }

    /**
     * ADR 079: the interface face is BUNDLED, not fetched. A webfont CDN the
     * office firewall blocks would silently render every screen in the
     * fallback, which is how a redesign "does nothing" in production.
     */
    public function test_the_interface_face_is_bundled_with_the_application(): void
    {
        $entry = (string) file_get_contents(resource_path('js/app.jsx'));
        $this->assertStringContainsString("import '@fontsource-variable/manrope';", $entry);

        $tailwind = (string) file_get_contents(base_path('tailwind.config.js'));
        $this->assertMatchesRegularExpression("/sans: \\['\"Manrope Variable\"'/", $tailwind);
    }
}
