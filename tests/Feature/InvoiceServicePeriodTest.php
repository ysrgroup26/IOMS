<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\InvoiceDocumentService;
use App\Services\SubscriptionLifecycleService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * ADR 091 -- the owner's case: a Starter subscription ended on 5 Nov, the
 * renewal invoice was billed for 5 Nov - 5 Dec, and the customer paid on
 * 17 Nov. The invoice must state the period actually bought (17 Nov - 17 Dec)
 * and say when the previous one ended.
 */
class InvoiceServicePeriodTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function subscription(string $endsAt): Subscription
    {
        $package = Package::create(['name' => 'Starter', 'slug' => 'starter-'.uniqid(), 'is_active' => true, 'is_public' => true,
            'price_monthly' => 239000, 'price_yearly' => 2390000, 'currency' => 'IDR']);
        $tenant = Tenant::create(['name' => 'Galangan Aliran Jaya', 'slug' => 'gaj-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        Company::withoutGlobalScopes()->create(['name' => 'GAJ', 'code' => 'GAJ', 'tenant_id' => $tenant->id, 'is_active' => true]);
        app(CurrentTenant::class)->set($tenant);

        return Subscription::create(['tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription', 'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => Carbon::parse('2026-10-05'), 'ends_at' => Carbon::parse($endsAt)]);
    }

    private function renewal(Subscription $s, string $start, string $end): Invoice
    {
        return Invoice::withoutGlobalScopes()->create(['tenant_id' => $s->tenant_id, 'subscription_id' => $s->id,
            'invoice_number' => 'INV-2026-4-00001', 'purpose' => Invoice::PURPOSE_RENEWAL, 'amount' => 239000,
            'currency' => 'IDR', 'status' => Invoice::STATUS_ISSUED, 'due_date' => '2026-11-19',
            'period_start' => $start, 'period_end' => $end]);
    }

    public function test_a_renewal_paid_after_the_lapse_states_the_period_it_bought(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-11-17 13:24:00');
        $s = $this->subscription('2026-11-05');
        $invoice = $this->renewal($s, '2026-11-05', '2026-12-05');

        $invoice->markPaid('INV7-REF', 'duitku');
        app(SubscriptionLifecycleService::class)->applyPaidInvoice($invoice->fresh());

        $fresh = $invoice->fresh();
        $this->assertSame('2026-11-17', $fresh->service_start->toDateString());
        $this->assertSame('2026-12-17', $fresh->service_end->toDateString());
        $this->assertSame('2026-11-05', $fresh->previous_period_end->toDateString());
        $this->assertSame('2026-12-17', $s->fresh()->ends_at->toDateString());

        $html = view('pdf.invoice', app(InvoiceDocumentService::class)->viewData($fresh))->render();
        $this->assertStringContainsString('17 Nov 2026', $html);
        $this->assertStringContainsString('17 Dec 2026', $html);
        $this->assertStringContainsString('Masa aktif sebelumnya berakhir 05 Nov 2026', $html);
        $this->assertStringNotContainsString('05 Dec 2026', $html);
    }

    public function test_an_invoice_settled_before_the_record_existed_is_derived_the_same_way(): void
    {
        $s = $this->subscription('2026-12-17');
        $invoice = $this->renewal($s, '2026-11-05', '2026-12-05');
        $invoice->forceFill(['status' => Invoice::STATUS_PAID, 'payment_date' => '2026-11-17'])->save();

        $p = $invoice->fresh()->servicePeriod();
        $this->assertSame('2026-11-17', $p['start']->toDateString());
        $this->assertSame('2026-12-17', $p['end']->toDateString());
        $this->assertSame('2026-11-05', $p['previous_end']->toDateString());
    }

    public function test_an_early_renewal_keeps_the_billed_period_and_no_note(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-11-01 09:00:00');
        $s = $this->subscription('2026-11-05');
        $invoice = $this->renewal($s, '2026-11-05', '2026-12-05');

        $invoice->markPaid('INV7-REF', 'duitku');
        app(SubscriptionLifecycleService::class)->applyPaidInvoice($invoice->fresh());

        $fresh = $invoice->fresh();
        $this->assertSame('2026-11-05', $fresh->service_start->toDateString());
        $this->assertSame('2026-12-05', $fresh->service_end->toDateString());
        $this->assertNull($fresh->previous_period_end);
        $html = view('pdf.invoice', app(InvoiceDocumentService::class)->viewData($fresh))->render();
        $this->assertStringNotContainsString('Masa aktif sebelumnya', $html);
    }
}
