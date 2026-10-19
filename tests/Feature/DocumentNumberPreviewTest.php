<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NumberingSequence;
use App\Models\Tenant;
use App\Models\User;
use App\Services\NumberGeneratorService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * v2.124.0 -- A PREVIEW MUST NOT TAKE A NUMBER.
 *
 * Twenty-four create pages told the reader "this will be saved as
 * INC-2026-00007" by calling `generate()`, which CONSUMES a sequence. So
 * opening a form and closing it again burned a document number for good,
 * and the number on the form was never the number the record got --
 * `store()` generates its own.
 *
 * Measured in a browser against MySQL 8 before the fix: three views of
 * /incidents/create moved the incident sequence from 2 to 5 while the
 * table held ONE incident, and `employee_cases` stood at sequence 3 with
 * zero rows. On a register an auditor reads, a gap in the numbering is a
 * question about deleted records -- and there was nothing to delete.
 */
class DocumentNumberPreviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->tenant = Tenant::create([
            'name' => 'Yard', 'slug' => 'yard-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->company = Company::withoutGlobalScopes()->create([
            'name' => 'Yard Co', 'code' => 'YC'.rand(10, 99),
            'tenant_id' => $this->tenant->id, 'is_active' => true,
        ]);

        app(CurrentTenant::class)->set($this->tenant->fresh());
    }

    private function sequenceFor(string $moduleKey): int
    {
        return (int) NumberingSequence::where('tenant_scope', $this->tenant->id)
            ->whereNull('company_id')
            ->where('module_key', $moduleKey)
            ->value('last_number');
    }

    public function test_a_preview_is_repeatable_and_consumes_nothing(): void
    {
        $numbers = app(NumberGeneratorService::class);

        $before = $this->sequenceFor('incident');

        $first = $numbers->preview('incident');
        $second = $numbers->preview('incident');
        $third = $numbers->preview('incident');

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
        $this->assertSame($before, $this->sequenceFor('incident'), 'A preview took a number.');
    }

    /** And it forecasts the number the record will actually get. */
    public function test_the_preview_is_the_number_the_next_record_receives(): void
    {
        $numbers = app(NumberGeneratorService::class);

        $forecast = $numbers->preview('permit_to_work');
        $issued = $numbers->generate('permit_to_work');

        $this->assertSame($forecast, $issued);
    }

    public function test_generating_still_advances_the_sequence(): void
    {
        $numbers = app(NumberGeneratorService::class);

        $before = $this->sequenceFor('ncr');

        $numbers->generate('ncr');
        $numbers->generate('ncr');

        $this->assertSame($before + 2, $this->sequenceFor('ncr'));
    }

    /**
     * A preview must not CREATE the sequence row either: a page view is a
     * read, and a brand-new tenant has issued nothing.
     */
    public function test_a_preview_on_a_fresh_tenant_writes_no_row(): void
    {
        $this->assertSame(
            0,
            NumberingSequence::where('tenant_scope', $this->tenant->id)->where('module_key', 'loto')->count(),
        );

        $preview = app(NumberGeneratorService::class)->preview('loto');

        $this->assertStringEndsWith('00001', $preview);
        $this->assertSame(
            0,
            NumberingSequence::where('tenant_scope', $this->tenant->id)->where('module_key', 'loto')->count(),
            'A preview created a sequence row.',
        );
    }

    /**
     * THE REGRESSION ITSELF, asked of the controllers rather than the
     * service: opening a create page twice must leave every sequence
     * exactly where it was.
     */
    public function test_opening_a_create_page_twice_burns_no_numbers(): void
    {
        $admin = User::create([
            'name' => 'Admin', 'email' => 'admin-'.uniqid().'@yard.test',
            'password' => bcrypt('secret-pass-1'), 'role' => User::ROLE_SUPER_ADMIN,
            'tenant_id' => $this->tenant->id, 'company_id' => $this->company->id,
            'is_active' => true, 'email_verified_at' => now(),
        ]);

        $before = NumberingSequence::where('tenant_scope', $this->tenant->id)
            ->pluck('last_number', 'module_key')->all();

        foreach (['incidents.create', 'safety-observations.create', 'hse-inspections.create'] as $routeName) {
            $this->actingAs($admin)->get(route($routeName))->assertOk();
            $this->actingAs($admin)->get(route($routeName))->assertOk();
        }

        $after = NumberingSequence::where('tenant_scope', $this->tenant->id)
            ->pluck('last_number', 'module_key')->all();

        $this->assertSame($before, $after, 'A create page consumed a document number.');
    }

    /**
     * No controller may hand a create page a CONSUMED number again. Asked
     * of the source, because that is the mistake that was made
     * twenty-four times and would be made again the next time somebody
     * copies a create() method.
     */
    public function test_no_create_page_prop_calls_the_consuming_generator(): void
    {
        $offenders = [];

        foreach (glob(app_path('Http/Controllers/*.php')) as $file) {
            $source = file_get_contents($file);

            if (preg_match_all("/'[a-z][A-Za-z]*(?:Number|Code)' => [A-Z]\w*::generate\w*\(/", $source, $matches)) {
                foreach ($matches[0] as $match) {
                    $offenders[] = basename($file).': '.$match;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'These Inertia props hand a page a number taken from the sequence:',
            ...$offenders,
            'Use the model\'s preview* sibling -- see NumberGeneratorService::preview().',
        ]));
    }
}
