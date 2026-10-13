<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Company;
use App\Models\CorrectiveAction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\ManHourLog;
use App\Models\P3kBox;
use App\Models\Package;
use App\Models\PermitToWork;
use App\Models\SafetyEquipment;
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
            // v2.116.0 -- the approved refinement's own props.
            'operationalKpis', 'accidentClassification', 'frequencyRates',
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

    /*
     * ==================================================================
     * v2.116.0 -- ACCIDENT CLASSIFICATION, FREQUENCY RATES, AND THE FIVE
     * OPERATIONAL KPIs.
     *
     * This file used to assert that LTIFR and TRIR stay unavailable,
     * because the controller said IOMS captured no recordability
     * classification. `incidents.injury_severity` has existed since
     * v2.73.0 -- the claim outlived the column -- so the rates are now
     * computed from real inputs, and what has to be pinned instead is
     * that they are computed CORRECTLY and that they refuse to answer
     * when their denominator is missing.
     * ==================================================================
     */

    /** A classified incident, in the year the Overview reports on. */
    private function classifiedIncident(string $injurySeverity, ?string $date = null): Incident
    {
        return Incident::create([
            'incident_number' => 'INC-'.uniqid(),
            'title' => 'Uji klasifikasi',
            'company_id' => $this->yard->id,
            'category' => 'injury',
            'severity' => 'moderate',
            'status' => Incident::STATUS_CLOSED,
            'incident_date' => $date ?? now()->toDateString(),
            'reported_by' => $this->hse->id,
            'injury_severity' => $injurySeverity,
            'description' => 'Uji',
        ]);
    }

    /**
     * Logs `$hours` of exposure this year, SPREAD ACROSS ROWS.
     *
     * `man_hour_logs.regular_hours` is `decimal(5,2)`, so one row holds at
     * most 999.99 hours. A first version of this helper put the whole
     * denominator in a single row: SQLite accepted it silently and MySQL
     * refused it outright, which is the portability trap CONVENTIONS.md
     * records -- and is also how real exposure accumulates anyway, a shift
     * at a time.
     */
    private function logManHours(float $hours): void
    {
        $employee = Employee::create([
            'employee_id' => 'EMP-'.uniqid(),
            'full_name' => 'Pekerja Uji',
            'company_id' => $this->yard->id,
            'status' => 'active',
        ]);

        $perRow = 500.0;
        $remaining = $hours;
        $day = 0;

        while ($remaining > 0) {
            ManHourLog::create([
                'company_id' => $this->yard->id,
                'employee_id' => $employee->id,
                'work_date' => now()->startOfYear()->addDays($day++)->toDateString(),
                'regular_hours' => min($perRow, $remaining),
                'overtime_hours' => 0,
                'recorded_by' => $this->hse->id,
            ]);

            $remaining -= $perRow;
        }
    }

    public function test_each_classification_counts_its_own_cases(): void
    {
        $this->classifiedIncident('fatality');
        $this->classifiedIncident('lost_time');
        $this->classifiedIncident('restricted_work');
        $this->classifiedIncident('medical_treatment');
        $this->classifiedIncident('first_aid');
        $this->classifiedIncident('first_aid');

        $byCode = collect($this->props()['accidentClassification']['classes'])->keyBy('code');

        $this->assertSame(1, $byCode['FAT']['value']);
        $this->assertSame(1, $byCode['LTI']['value']);
        $this->assertSame(1, $byCode['RWC']['value']);
        $this->assertSame(1, $byCode['MTC']['value']);
        $this->assertSame(2, $byCode['FAC']['value']);
    }

    /**
     * An incident carries ONE classification, so the five counts are
     * disjoint and their sum is the number of classified incidents --
     * never more. This is what stops a case being counted twice.
     */
    public function test_a_case_is_counted_in_exactly_one_classification(): void
    {
        $this->classifiedIncident('lost_time');
        $this->classifiedIncident('medical_treatment');
        $this->classifiedIncident('first_aid');

        $classification = $this->props()['accidentClassification'];

        $this->assertSame(3, $classification['total_cases']);
        $this->assertSame(
            3,
            collect($classification['classes'])->sum('value'),
            'The five classifications must partition the cases, not overlap.'
        );
    }

    /** `none` and NULL are not the lowest classification -- they are no classification. */
    public function test_an_unclassified_injury_is_reported_and_counted_nowhere(): void
    {
        $this->classifiedIncident('first_aid');
        $this->incident(now()->toDateString()); // category injury, no injury_severity

        $classification = $this->props()['accidentClassification'];

        $this->assertSame(1, $classification['total_cases'], 'An unclassified report must not be folded into a class.');
        $this->assertSame(1, $classification['unclassified'], 'And the gap must be reported rather than hidden.');
    }

    public function test_recordable_excludes_first_aid(): void
    {
        $this->classifiedIncident('fatality');
        $this->classifiedIncident('lost_time');
        $this->classifiedIncident('restricted_work');
        $this->classifiedIncident('medical_treatment');
        $this->classifiedIncident('first_aid');
        $this->classifiedIncident('first_aid');

        $props = $this->props();

        $this->assertSame(4, $props['accidentClassification']['recordable'], 'FAT + LTI + RWC + MTC, and no first aid.');
        $this->assertSame(
            4,
            $props['safetyKpi']['recordable_injuries_ytd'],
            'The Man-Hour panel must read the same recordable figure, not every injury-category incident.'
        );
    }

    public function test_a_classification_from_last_year_is_not_in_this_years_count(): void
    {
        $this->classifiedIncident('lost_time', now()->subYear()->toDateString());

        $this->assertSame(0, $this->props()['accidentClassification']['total_cases']);
    }

    public function test_classification_does_not_cross_the_tenant_boundary(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Pesaing', 'slug' => 'p-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);
        $otherCompany = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Lain', 'code' => 'YL', 'tenant_id' => $otherTenant->id, 'is_active' => true,
        ]);

        Incident::withoutGlobalScopes()->create([
            'incident_number' => 'INC-OTHER-'.uniqid(), 'title' => 'Milik tenant lain',
            'company_id' => $otherCompany->id, 'category' => 'injury', 'severity' => 'major',
            'status' => Incident::STATUS_CLOSED, 'incident_date' => now(),
            'reported_by' => $this->hse->id, 'injury_severity' => 'lost_time', 'description' => 'X',
        ]);

        $this->assertSame(
            0,
            $this->props()['accidentClassification']['total_cases'],
            "Another tenant's classified case must not reach this classification panel."
        );
    }

    // --- the rates ---------------------------------------------------------

    /**
     * NO EXPOSURE HOURS MEANS NO RATE. Dividing by nothing is undefined,
     * and "TRIR 0.00" on a site that logged no hours reads as a perfect
     * safety record -- the single most dangerous output this panel could
     * produce.
     */
    public function test_a_rate_is_unavailable_without_exposure_hours(): void
    {
        $this->classifiedIncident('lost_time');

        $rates = $this->props()['frequencyRates'];

        $this->assertFalse($rates['available']);
        $this->assertNotEmpty($rates['unavailable_reason']);

        foreach ($rates['rates'] as $rate) {
            $this->assertNull($rate['value'], "{$rate['code']} must be null, never 0, without a denominator.");
        }
    }

    public function test_trir_is_recordable_cases_over_exposure_hours(): void
    {
        $this->classifiedIncident('lost_time');
        $this->classifiedIncident('medical_treatment');
        $this->classifiedIncident('first_aid'); // not recordable
        $this->logManHours(100000);

        $rates = collect($this->props()['frequencyRates']['rates'])->keyBy('code');

        // 2 recordable * 200,000 / 100,000 = 4.00
        $this->assertTrue($this->props()['frequencyRates']['available']);
        $this->assertSame(4.0, $rates['TRIR']['value']);
        $this->assertSame(2, $rates['TRIR']['cases']);
        $this->assertSame(200000, $rates['TRIR']['multiplier']);
    }

    public function test_ltifr_counts_lost_time_and_fatality_only(): void
    {
        $this->classifiedIncident('lost_time');
        $this->classifiedIncident('fatality');
        $this->classifiedIncident('restricted_work'); // recordable, not lost time
        $this->logManHours(100000);

        $rates = collect($this->props()['frequencyRates']['rates'])->keyBy('code');

        // 2 cases * 1,000,000 / 100,000 = 20.00
        $this->assertSame(20.0, $rates['LTIFR']['value']);
        $this->assertSame(2, $rates['LTIFR']['cases']);
        $this->assertSame(1000000, $rates['LTIFR']['multiplier']);
    }

    /** The denominator is named on screen, so it must be in the payload. */
    public function test_the_rate_payload_states_the_denominator_it_used(): void
    {
        $this->logManHours(12500);

        $rates = $this->props()['frequencyRates'];

        $this->assertSame(12500.0, (float) $rates['man_hours']);
        $this->assertStringContainsString((string) now()->year, $rates['period_label']);
    }

    // --- the five operational KPIs -----------------------------------------

    public function test_the_five_operational_kpis_arrive_in_the_approved_order(): void
    {
        $this->assertSame(
            ['permits', 'incidents', 'ppe', 'inspections', 'material'],
            collect($this->props()['operationalKpis'])->pluck('key')->all()
        );
    }

    /**
     * THE DETAIL LINE MUST PARTITION THE VALUE ABOVE IT. A breakdown that
     * counted a different set would read as one and not be one.
     */
    public function test_every_kpi_detail_adds_up_to_its_own_value(): void
    {
        foreach ($this->props()['operationalKpis'] as $kpi) {
            $this->assertSame(
                $kpi['value'],
                collect($kpi['detail'])->sum('value'),
                "The [{$kpi['key']}] detail line does not add up to the number it sits under."
            );
        }
    }

    public function test_every_kpi_has_a_destination_and_a_label(): void
    {
        foreach ($this->props()['operationalKpis'] as $kpi) {
            $this->assertNotEmpty($kpi['label']);
            $this->assertNotEmpty($kpi['href'], "The [{$kpi['key']}] card must not be a dead click.");
        }
    }

    /**
     * A permit whose end time has passed and which nobody closed is still
     * open, and it is the half of the count that matters operationally.
     */
    public function test_an_expired_permit_is_counted_and_named_separately(): void
    {
        PermitToWork::create([
            'ptw_number' => 'PTW-OPEN-'.uniqid(),
            'company_id' => $this->yard->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Berjalan.',
            'start_datetime' => now()->subHour(),
            'end_datetime' => now()->addHours(4),
            'requested_by' => $this->hse->id,
            'status' => PermitToWork::STATUS_ACTIVE,
        ]);

        PermitToWork::create([
            'ptw_number' => 'PTW-PAST-'.uniqid(),
            'company_id' => $this->yard->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Lewat waktu.',
            'start_datetime' => now()->subDays(2),
            'end_datetime' => now()->subDay(),
            'requested_by' => $this->hse->id,
            'status' => PermitToWork::STATUS_APPROVED,
        ]);

        $permits = collect($this->props()['operationalKpis'])->firstWhere('key', 'permits');

        $this->assertSame(2, $permits['value']);
        $this->assertSame(1, collect($permits['detail'])->firstWhere('label', 'active')['value']);
        $this->assertSame(1, collect($permits['detail'])->firstWhere('label', 'past end time')['value']);
    }

    /**
     * The inspections card counts the only scheduled inspections IOMS
     * has: the next-due date on safety equipment and on a P3K box.
     * `hse_inspections` records inspections that HAPPENED and has no
     * scheduled date at all.
     */
    public function test_inspections_due_counts_scheduled_equipment_and_p3k_dates(): void
    {
        SafetyEquipment::create([
            'company_id' => $this->yard->id, 'name' => 'APAR A', 'type' => 'fire_extinguisher',
            'status' => 'active', 'next_inspection_due' => now()->addDays(3)->toDateString(),
        ]);
        SafetyEquipment::create([
            'company_id' => $this->yard->id, 'name' => 'APAR B', 'type' => 'fire_extinguisher',
            'status' => 'active', 'next_inspection_due' => now()->subDays(4)->toDateString(),
        ]);
        P3kBox::create([
            'company_id' => $this->yard->id, 'location' => 'Workshop',
            'next_inspection_due' => now()->addDays(2)->toDateString(),
        ]);

        $inspections = collect($this->props()['operationalKpis'])->firstWhere('key', 'inspections');

        $this->assertSame(2, collect($inspections['detail'])->firstWhere('label', 'this week')['value']);
        $this->assertSame(1, collect($inspections['detail'])->firstWhere('label', 'overdue')['value']);
        $this->assertSame(3, $inspections['value']);
    }

    public function test_the_operational_kpis_respect_the_operating_unit_filter(): void
    {
        PermitToWork::create([
            'ptw_number' => 'PTW-F-'.uniqid(),
            'company_id' => $this->yard->id,
            'permit_type' => 'hot_work',
            'work_description' => 'Milik yard.',
            'start_datetime' => now(),
            'end_datetime' => now()->addHours(4),
            'requested_by' => $this->hse->id,
            'status' => PermitToWork::STATUS_ACTIVE,
        ]);

        $permits = collect($this->props(['company_id' => $this->yard->id])['operationalKpis'])->firstWhere('key', 'permits');
        $this->assertSame(1, $permits['value']);
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
