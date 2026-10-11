<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\Incident;
use App\Models\Package;
use App\Models\SafetyObservation;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentTenant;
use Database\Seeders\ModuleSeeder;
use Database\Seeders\WorkspaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * THE HSE OVERVIEW REDESIGN (v2.109.0), AT THE LEVEL THAT CAN BREAK.
 *
 * The redesign is mostly presentation, and presentation is not what this
 * file tests. What it tests is the contract underneath it: the props the
 * new page reads must exist, carry real data, stay inside the tenant, and
 * keep telling the truth about what IOMS does not know.
 *
 * Four things in particular, because each one is a way the page could
 * look finished while being wrong:
 *
 *  - the trend series is real and bucketed by month, and reports
 *    `has_data: false` rather than drawing twelve flat zeroes;
 *  - the calendar window covers three months so the month grid can
 *    navigate, and carries only this tenant's HSE events;
 *  - Quick Access is filtered SERVER-side by the same predicates the
 *    destination routes enforce;
 *  - LTI/LTIFR/TRIR stay unavailable, and man-hours stay null rather
 *    than zero, on a system with nothing recorded.
 */
class HseOverviewDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $yard;

    private User $hse;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(WorkspaceSeeder::class);
        $this->seed(ModuleSeeder::class);

        $package = Package::where('slug', 'business')->sole();

        $this->tenant = Tenant::create([
            'name' => 'Galangan', 'slug' => 'g-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->yard = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Utama', 'code' => 'YU', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->tenant->workspaces()->sync(
            Workspace::whereIn('key', $package->defaultWorkspaceKeys())->pluck('id')
        );

        app(CurrentTenant::class)->set($this->tenant->fresh());

        $this->hse = User::create([
            'name' => 'HSE Lead', 'email' => 'hse-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_HSE,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);
    }

    private function props(array $query = []): array
    {
        $response = $this->actingAs($this->hse)->get(route('hse.dashboard', $query));
        $response->assertOk();

        return $response->viewData('page')['props'];
    }

    private function incident(string $date, string $severity = 'low'): Incident
    {
        return Incident::create([
            'incident_number' => 'INC-'.uniqid(),
            'title' => 'Tergelincir di dek',
            'company_id' => $this->yard->id,
            'category' => 'injury',
            'severity' => $severity,
            'status' => Incident::STATUS_REPORTED,
            'incident_date' => $date,
            'reported_by' => $this->hse->id,
            'location' => 'Dek A',
            'description' => 'Uji',
        ]);
    }

    // --- the page still renders ------------------------------------------

    public function test_the_overview_renders_for_an_hse_user(): void
    {
        $response = $this->actingAs($this->hse)->get(route('hse.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Hse/Dashboard'));
    }

    /** Every prop the redesigned page destructures must be served. */
    public function test_the_page_receives_every_prop_the_redesign_reads(): void
    {
        $props = $this->props();

        foreach ([
            'openIncidentsCount', 'incidentsBySeverity', 'ppeAlertCount', 'recentIncidents',
            'openSafetyObservationsCount', 'recentSafetyObservations', 'recentActivity',
            'openPermitsCount', 'overdueSafetyEquipmentCount', 'overdueP3kCount', 'openCapaCount',
            'actionRequired', 'manHours', 'safetyKpi', 'wasteSummary', 'canManageHse',
            'safetyTrend', 'calendar', 'quickAccess', 'companies', 'filters',
        ] as $prop) {
            $this->assertArrayHasKey($prop, $props, "The Overview reads [{$prop}] and the controller must send it.");
        }
    }

    // --- C. the trend ------------------------------------------------------

    public function test_the_trend_covers_twelve_months_and_four_series(): void
    {
        $trend = $this->props()['safetyTrend'];

        $this->assertCount(12, $trend['labels']);
        $this->assertSame(now()->format('Y-m'), $trend['labels'][11], 'The window must end on the current month.');
        $this->assertSame(
            ['incidents', 'observations', 'capa', 'permits'],
            array_column($trend['series'], 'key')
        );

        foreach ($trend['series'] as $series) {
            $this->assertCount(12, $series['data'], "[{$series['key']}] must have one bucket per month.");
        }
    }

    /**
     * An empty system must say so. Twelve months of zero drawn as a flat
     * line reads as "nothing went wrong", which is a claim IOMS cannot
     * make about a database with nothing in it.
     */
    public function test_an_empty_system_reports_no_trend_data(): void
    {
        $this->assertFalse($this->props()['safetyTrend']['has_data']);
    }

    public function test_a_real_incident_lands_in_its_own_month_bucket(): void
    {
        $this->incident(now()->toDateString());
        $this->incident(now()->subMonths(2)->toDateString());

        $trend = $this->props()['safetyTrend'];
        $incidents = collect($trend['series'])->firstWhere('key', 'incidents')['data'];

        $this->assertTrue($trend['has_data']);
        $this->assertSame(1, $incidents[11], 'This month holds one incident.');
        $this->assertSame(1, $incidents[9], 'Two months ago holds the other.');
        $this->assertSame(2, array_sum($incidents));
    }

    public function test_an_incident_older_than_the_window_is_not_counted(): void
    {
        $this->incident(now()->subMonths(18)->toDateString());

        $trend = $this->props()['safetyTrend'];
        $incidents = collect($trend['series'])->firstWhere('key', 'incidents')['data'];

        $this->assertSame(0, array_sum($incidents));
        $this->assertFalse($trend['has_data']);
    }

    public function test_observations_and_capa_are_counted_in_their_own_series(): void
    {
        SafetyObservation::create([
            'observation_number' => 'OBS-1', 'company_id' => $this->yard->id,
            'type' => 'unsafe_condition', 'severity' => 'low', 'status' => SafetyObservation::STATUS_OPEN,
            'observed_at' => now(), 'reported_by' => $this->hse->id,
            'location' => 'Dek A', 'description' => 'Uji',
        ]);

        CorrectiveAction::create([
            'company_id' => $this->yard->id, 'action' => 'Pasang rambu',
            'status' => CorrectiveAction::STATUS_OPEN, 'due_date' => now()->addWeek(),
            'source_type' => Incident::class, 'source_id' => $this->incident(now()->toDateString())->id,
        ]);

        $series = collect($this->props()['safetyTrend']['series'])->keyBy('key');

        $this->assertSame(1, array_sum($series['observations']['data']));
        $this->assertSame(1, array_sum($series['capa']['data']));
    }

    /** Another tenant's incident must never reach this tenant's chart. */
    public function test_the_trend_does_not_cross_the_tenant_boundary(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Pesaing', 'slug' => 'p-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Lain', 'code' => 'YL', 'tenant_id' => $otherTenant->id, 'is_active' => true,
        ]);

        Incident::withoutGlobalScopes()->create([
            'incident_number' => 'INC-OTHER', 'title' => 'Milik tenant lain',
            'company_id' => $otherCompany->id, 'category' => 'injury', 'severity' => 'critical',
            'status' => Incident::STATUS_REPORTED, 'incident_date' => now(),
            'reported_by' => $this->hse->id, 'location' => 'X', 'description' => 'X',
        ]);

        $trend = $this->props()['safetyTrend'];

        $this->assertFalse($trend['has_data'], "Another tenant's incident must not appear in this trend.");
    }

    // --- E. the calendar ---------------------------------------------------

    public function test_the_calendar_window_spans_three_months(): void
    {
        $calendar = $this->props()['calendar'];

        $this->assertSame(now()->startOfMonth()->subMonth()->toDateString(), $calendar['window']['start']);
        $this->assertSame(now()->endOfMonth()->addMonth()->toDateString(), $calendar['window']['end']);
        $this->assertSame(now()->toDateString(), $calendar['today']);
    }

    public function test_an_hse_event_in_the_window_reaches_the_calendar(): void
    {
        CalendarEvent::create([
            'company_id' => $this->yard->id,
            'title' => 'Inspeksi APAR',
            'start_at' => now()->addDays(3),
            'all_day' => true,
            'event_type' => 'reminder',
            'department_key' => 'hse',
            'created_by' => $this->hse->id,
        ]);

        $events = $this->props()['calendar']['events'];

        $this->assertNotEmpty($events);
        $this->assertSame('Inspeksi APAR', $events[0]['title']);
        $this->assertArrayHasKey('event_type', $events[0], 'The widget colours events by their type.');
    }

    /**
     * The window is what makes month navigation honest: an event in the
     * next month has to be there before the user pages to it.
     */
    public function test_an_event_in_the_next_month_is_already_loaded(): void
    {
        CalendarEvent::create([
            'company_id' => $this->yard->id,
            'title' => 'Audit bulan depan',
            'start_at' => now()->addMonth()->startOfMonth()->addDays(2),
            'all_day' => true,
            'event_type' => 'meeting',
            'department_key' => 'hse',
            'created_by' => $this->hse->id,
        ]);

        $titles = collect($this->props()['calendar']['events'])->pluck('title');

        $this->assertTrue($titles->contains('Audit bulan depan'));
    }

    public function test_another_departments_event_is_not_shown_on_the_hse_calendar(): void
    {
        CalendarEvent::create([
            'company_id' => $this->yard->id,
            'title' => 'Rapat HRD',
            'start_at' => now()->addDays(2),
            'all_day' => true,
            'event_type' => 'meeting',
            'department_key' => 'hrd',
            'created_by' => $this->hse->id,
        ]);

        $titles = collect($this->props()['calendar']['events'])->pluck('title');

        $this->assertFalse($titles->contains('Rapat HRD'));
    }

    // --- G. quick access is gated on the server ---------------------------

    public function test_quick_access_only_offers_what_the_account_may_do(): void
    {
        $actions = collect($this->props()['quickAccess']);

        $this->assertTrue($actions->contains('key', 'capa'), 'The CAPA register is open to any HSE viewer.');

        foreach ($actions as $action) {
            $this->assertArrayHasKey('href', $action);
            $this->assertNotEmpty($action['href']);
        }
    }

    /**
     * A Warehouse account can reach neither incident reporting nor
     * observation creation, so neither tile may be offered to one.
     */
    public function test_quick_access_hides_actions_an_account_cannot_perform(): void
    {
        $warehouse = User::create([
            'name' => 'Gudang', 'email' => 'wh-'.uniqid().'@g.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_WAREHOUSE,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->yard->id, 'is_active' => true,
        ]);

        $response = $this->actingAs($warehouse)->get(route('hse.dashboard'));

        if ($response->status() !== 200) {
            $this->markTestSkipped('This account cannot open the HSE Overview at all, which is a stronger gate.');
        }

        $keys = collect($response->viewData('page')['props']['quickAccess'])->pluck('key');

        $this->assertFalse($keys->contains('incident'), 'Incident reporting is not this account\'s to start.');
        $this->assertFalse($keys->contains('observation'));
    }

    // --- F. the honest metrics --------------------------------------------

    /** Null, not zero: nobody has logged hours, which is not the same as none worked. */
    public function test_man_hours_are_null_when_nothing_has_been_logged(): void
    {
        $manHours = $this->props()['manHours'];

        $this->assertNull($manHours['today']);
        $this->assertNull($manHours['this_month']);
        $this->assertNull($manHours['ytd']);
    }

    public function test_the_lost_time_rates_remain_unavailable(): void
    {
        $this->assertFalse(
            $this->props()['safetyKpi']['lost_time_metrics_available'],
            'LTIFR/TRIR need exposure hours and a recordability classification IOMS does not capture.'
        );
    }

    // --- A. the operating-unit filter --------------------------------------

    public function test_the_filter_narrows_the_figures_to_one_operating_unit(): void
    {
        $dock = Company::withoutGlobalScopes()->create([
            'name' => 'Dock Kedua', 'code' => 'DK', 'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        Incident::create([
            'incident_number' => 'INC-DOCK', 'title' => 'Di dock', 'company_id' => $dock->id,
            'category' => 'injury', 'severity' => 'low', 'status' => Incident::STATUS_REPORTED,
            'incident_date' => now(), 'reported_by' => $this->hse->id, 'location' => 'Dock', 'description' => 'Uji',
        ]);

        $this->assertSame(1, $this->props()['openIncidentsCount'], 'Unfiltered, the tenant sees it.');
        $this->assertSame(0, $this->props(['company_id' => $this->yard->id])['openIncidentsCount'], 'Filtered to the yard, it is out of scope.');
        $this->assertSame(1, $this->props(['company_id' => $dock->id])['openIncidentsCount']);
    }

    /** A company id belonging to someone else resolves to nothing, not to their data. */
    public function test_the_filter_cannot_name_another_tenants_operating_unit(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Pesaing', 'slug' => 'p-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Lain', 'code' => 'YL', 'tenant_id' => $otherTenant->id, 'is_active' => true,
        ]);

        Incident::withoutGlobalScopes()->create([
            'incident_number' => 'INC-X', 'title' => 'Rahasia', 'company_id' => $otherCompany->id,
            'category' => 'injury', 'severity' => 'critical', 'status' => Incident::STATUS_REPORTED,
            'incident_date' => now(), 'reported_by' => $this->hse->id, 'location' => 'X', 'description' => 'X',
        ]);

        $props = $this->props(['company_id' => $otherCompany->id]);

        $this->assertSame(0, $props['openIncidentsCount']);
        $this->assertSame(0, $props['incidentsBySeverity']['critical'] ?? 0);
    }

    public function test_the_filter_offers_only_this_tenants_operating_units(): void
    {
        Tenant::create(['name' => 'Pesaing', 'slug' => 'p-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);

        $names = collect($this->props()['companies'])->pluck('name');

        $this->assertTrue($names->contains('Yard Utama'));
        $this->assertFalse($names->contains('Yard Lain'));
    }

    // --- the header shortcut is gone, the route is not --------------------

    public function test_the_full_calendar_is_still_reachable(): void
    {
        $this->actingAs($this->hse)->get(route('calendar.index'))->assertOk();
    }

    public function test_the_header_no_longer_carries_a_calendar_shortcut(): void
    {
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));

        $this->assertStringNotContainsString("route('calendar.index')", $layout);
    }

    /**
     * The redesign is scoped to this page. Every other department Overview
     * still composes through the shared shell, and nothing may have
     * quietly adopted the new kit.
     */
    public function test_no_other_overview_was_redesigned(): void
    {
        $others = glob(resource_path('js/Pages/*/Dashboard.jsx'));

        foreach ($others as $file) {
            if (str_contains($file, '/Hse/')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'hse-overview',
                file_get_contents($file),
                basename(dirname($file)).' must not use the HSE Overview component kit yet.'
            );
        }
    }
}
