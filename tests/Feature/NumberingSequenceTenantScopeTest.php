<?php

namespace Tests\Feature;

use App\Models\NumberingSequence;
use App\Models\Tenant;
use App\Services\NumberGeneratorService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.41.0 -- per-tenant document numbering.
 *
 * The counter was shared across every tenant on the platform:
 * `numbering_formats` gained a `tenant_id` in Milestone 3, the sequence
 * never did. Each customer therefore saw GAPS in its own document numbers
 * wherever another customer consumed the shared counter -- a weak volume
 * disclosure, and in an HSE context a permit register with holes reads to
 * an auditor like missing or destroyed records.
 *
 * The property that matters most here is not isolation on its own but
 * CONTINUITY WITHOUT RE-ISSUE: a tenant's series must never restart at a
 * number it has already used.
 */
class NumberingSequenceTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug): Tenant
    {
        return Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    private function asTenant(?Tenant $tenant): void
    {
        app(CurrentTenant::class)->set($tenant);
    }

    private function generate(string $module = 'permit_to_work'): string
    {
        return app(NumberGeneratorService::class)->generate($module);
    }

    private function seq(string $number): int
    {
        // Trailing digit group of e.g. "PTW-2026-00042".
        preg_match('/(\d+)$/', $number, $m);

        return (int) ($m[1] ?? 0);
    }

    /** The defect: interleaved tenants used to consume one shared counter. */
    public function test_each_tenant_has_its_own_uninterrupted_series(): void
    {
        $a = $this->tenant('acme');
        $b = $this->tenant('borneo');

        $aNumbers = [];
        $bNumbers = [];

        // Interleave deliberately -- this is the exact pattern that used to
        // punch holes in both tenants' registers.
        for ($i = 0; $i < 3; $i++) {
            $this->asTenant($a);
            $aNumbers[] = $this->seq($this->generate());

            $this->asTenant($b);
            $bNumbers[] = $this->seq($this->generate());
        }

        $this->assertSame([1, 2, 3], $aNumbers, 'Tenant A series has gaps.');
        $this->assertSame([1, 2, 3], $bNumbers, 'Tenant B series has gaps.');
    }

    /**
     * Two tenants legitimately holding the same number string is CORRECT --
     * a document number is unique within a customer, like an invoice number.
     * Pinned so nobody "fixes" it back into a shared counter later.
     */
    public function test_two_tenants_may_hold_the_same_document_number(): void
    {
        $a = $this->tenant('acme');
        $b = $this->tenant('borneo');

        $this->asTenant($a);
        $first = $this->generate();

        $this->asTenant($b);
        $second = $this->generate();

        $this->assertSame($first, $second);
    }

    /** Counters must not bleed between modules or between periods. */
    public function test_series_are_independent_per_module(): void
    {
        $this->asTenant($this->tenant('acme'));

        $this->assertSame(1, $this->seq($this->generate('permit_to_work')));
        $this->assertSame(2, $this->seq($this->generate('permit_to_work')));
        $this->assertSame(1, $this->seq($this->generate('incident')), 'Incident series inherited the PTW counter.');
    }

    /**
     * THE CRITICAL SAFETY PROPERTY. The migration seeds each tenant's row
     * from the shared high-water mark rather than 0, precisely so the next
     * number issued cannot collide with a document that already exists.
     * This pins that a seeded counter continues forward and never restarts.
     */
    public function test_a_seeded_counter_continues_forward_and_never_reissues(): void
    {
        $a = $this->tenant('acme');
        $this->asTenant($a);

        // Stand in for the migration's backfill: this tenant already holds
        // documents up to 42, carried over from the previously shared counter.
        NumberingSequence::create([
            'tenant_id' => $a->id,
            'tenant_scope' => $a->id,
            'company_id' => null,
            'company_scope' => 0,
            'module_key' => 'permit_to_work',
            'period_key' => now()->format('Y'),
            'last_number' => 42,
        ]);

        $next = $this->seq($this->generate());

        $this->assertSame(43, $next, 'A seeded tenant counter must continue, never restart at 1.');
        $this->assertGreaterThan(42, $next, 'Issuing 42 or below would duplicate a live document number.');
    }

    /** A genuinely new tenant has issued nothing, so starting at 1 is correct. */
    public function test_a_brand_new_tenant_starts_at_one(): void
    {
        $this->asTenant($this->tenant('fresh'));

        $this->assertSame(1, $this->seq($this->generate()));
    }

    /**
     * Console and scheduler runs have no tenant. They must fall to the
     * platform counter rather than silently consuming a tenant's series.
     */
    public function test_an_unresolved_tenant_uses_the_platform_counter(): void
    {
        $a = $this->tenant('acme');

        $this->asTenant($a);
        $this->generate();
        $this->generate();

        $this->asTenant(null);
        $this->assertSame(1, $this->seq($this->generate()), 'Platform scope borrowed the tenant counter.');

        $this->asTenant($a);
        $this->assertSame(3, $this->seq($this->generate()), 'Platform generation consumed a tenant number.');
    }

    /** One counter row per (tenant, module, period) -- no duplicates from the firstOrCreate path. */
    public function test_counter_rows_are_unique_per_tenant_module_and_period(): void
    {
        $a = $this->tenant('acme');
        $this->asTenant($a);

        $this->generate();
        $this->generate();
        $this->generate();

        $rows = NumberingSequence::where('tenant_scope', $a->id)
            ->where('module_key', 'permit_to_work')
            ->where('period_key', now()->format('Y'))
            ->count();

        $this->assertSame(1, $rows);
        $this->assertSame(3, NumberingSequence::where('tenant_scope', $a->id)
            ->where('module_key', 'permit_to_work')->value('last_number'));
    }

    /**
     * Period semantics must survive per-tenant scoping. `vendor` resets
     * NEVER (period_key 'ALL') while `permit_to_work` resets yearly, so the
     * two must not share a counter row even within one tenant.
     *
     * (Deliberately NOT asserting FK cascade-on-delete here: SQLite cannot
     * add a foreign key to an existing table, so such a test would pass on
     * MySQL and fail on the test database while proving nothing about this
     * release's logic. Stranded counter rows after a tenant delete are inert
     * anyway -- tenant ids are never reused.)
     */
    public function test_reset_period_semantics_are_preserved_per_tenant(): void
    {
        $a = $this->tenant('acme');
        $this->asTenant($a);

        $this->generate('permit_to_work');
        $this->generate('permit_to_work');

        $this->assertSame(1, $this->seq($this->generate('vendor')), 'Never-reset module inherited the yearly counter.');

        $periods = NumberingSequence::where('tenant_scope', $a->id)
            ->pluck('period_key', 'module_key')->all();

        $this->assertSame('ALL', $periods['vendor']);
        $this->assertSame(now()->format('Y'), $periods['permit_to_work']);
    }
}
