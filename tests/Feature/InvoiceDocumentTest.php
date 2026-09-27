<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\InvoiceDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * v2.81.0 -- THE INVOICE SAID ITS OWN NAME TWICE.
 *
 * It rendered through `pdf.partials.letterhead`, which is built to print a
 * TENANT's identity -- logo, company name, legal name. Fed the ISSUER
 * identity it drew the IOMS lockup, artwork that already reads "IOMS",
 * directly beside the text "IOMS".
 *
 * The redesign gives the invoice its own header (the mark alone, the name
 * as type, the descriptor beneath it, on the navy band customers know from
 * IOMS email) and leaves the shared letterhead untouched, because every
 * tenant document depends on it.
 *
 * What is pinned here is the part a redesign can quietly undo: that the
 * brand is stated once, that the shared letterhead is not being used, that
 * nothing about the invoice DATA changed, and that the document still
 * refuses to invent an issuer identity it does not have.
 */
class InvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $package = Package::create([
            'name' => 'Business', 'slug' => 'business-'.uniqid(), 'is_active' => true, 'is_public' => true,
            'price_monthly' => 1499000, 'price_yearly' => 14990000, 'currency' => 'IDR',
        ]);

        $tenant = Tenant::create([
            'name' => 'Galangan Aliran Jaya', 'slug' => 'gaj-'.uniqid(), 'status' => Tenant::STATUS_ACTIVE,
        ]);

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id, 'package_id' => $package->id,
            'status' => Subscription::STATUS_ACTIVE, 'type' => 'subscription',
            'billing_cycle' => Subscription::CYCLE_MONTHLY,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDays(20),
        ]);

        $this->invoice = Invoice::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'invoice_number' => 'INV-TEST-00001',
            'purpose' => Invoice::PURPOSE_RENEWAL,
            'amount' => 1499000, 'currency' => 'IDR',
            'status' => Invoice::STATUS_ISSUED,
            'due_date' => now()->addDays(14),
            'period_start' => now()->addDays(20),
            'period_end' => now()->addDays(50),
        ]);
    }

    private function render(?Invoice $invoice = null): string
    {
        $invoice = $invoice ?? $this->invoice;

        return view('pdf.invoice', app(InvoiceDocumentService::class)->viewData($invoice))->render();
    }

    /* ==================================================================
     * The header
     * ================================================================== */

    public function test_the_brand_name_is_stated_once_and_as_type(): void
    {
        $html = $this->render();

        // The mark WITHOUT the wordmark: the lockup beside the word IOMS is
        // exactly the duplication this release removed.
        $this->assertStringContainsString('ioms-icon.svg', $html);
        $this->assertStringNotContainsString('ioms-logo.svg', $html);

        $this->assertStringContainsString('>IOMS</div>', $html);
        $this->assertStringContainsString('Industrial Operations Platform', $html);
    }

    public function test_the_shared_tenant_letterhead_is_not_used(): void
    {
        // The partial's ELEMENTS, not its class names: the shared stylesheet
        // defines those rules for every document and is still included, so
        // searching the whole file would match the CSS. If a letterhead
        // element reappears, the invoice has been quietly reconnected to the
        // partial every tenant document depends on -- and the duplication
        // comes back with it.
        $html = $this->render();

        $this->assertStringNotContainsString('class="doc-letterhead"', $html);
        $this->assertStringNotContainsString('class="doc-company-name"', $html);
        $this->assertStringNotContainsString('class="logo-cell"', $html);
    }

    public function test_the_header_carries_the_document_type_and_number(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('INVOICE', $html);
        $this->assertStringContainsString('INV-TEST-00001', $html);
    }

    public function test_the_mark_is_declared_with_both_dimensions(): void
    {
        // dompdf will draw an image to whatever one dimension says and guess
        // the other. Both are declared so the 1:1 mark cannot come out oval.
        $this->assertMatchesRegularExpression(
            '/<img[^>]+ioms-icon\.svg[^>]+width="34"[^>]+height="34"/',
            $this->render()
        );
    }

    public function test_the_mark_file_is_the_official_artwork_and_is_transparent(): void
    {
        $path = app(InvoiceDocumentService::class)->issuerIdentity()['mark_url'];

        $this->assertFileExists($path);

        $svg = file_get_contents($path);

        // No background rectangle, and no trace of the near-white ground the
        // designer's square export carries.
        $this->assertStringNotContainsString('<rect', $svg);
        $this->assertStringNotContainsString('f7fafc', $svg);

        // The mark's own cyan, untouched.
        $this->assertStringContainsString('#01c1ed', $svg);
    }

    /* ==================================================================
     * The data, unchanged
     * ================================================================== */

    public function test_every_invoice_fact_still_reaches_the_document(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Galangan Aliran Jaya', $html);
        $this->assertStringContainsString('IDR 1.499.000', $html);
        $this->assertStringContainsString('BELUM DIBAYAR', $html);
        $this->assertStringContainsString($this->invoice->due_date->format('d M Y'), $html);
        $this->assertStringContainsString($this->invoice->period_start->format('d M Y'), $html);
        $this->assertStringContainsString(config('ioms.emails.billing'), $html);
        $this->assertStringContainsString(config('ioms.emails.support'), $html);
    }

    public function test_a_paid_invoice_says_so_and_shows_how_it_was_settled(): void
    {
        $this->invoice->update([
            'status' => Invoice::STATUS_PAID,
            'payment_date' => now(),
            'payment_method' => 'midtrans',
            'payment_reference' => 'REF-99',
        ]);

        $html = $this->render($this->invoice->fresh());

        $this->assertStringContainsString('LUNAS', $html);
        $this->assertStringContainsString('MIDTRANS', $html);
        $this->assertStringContainsString('REF-99', $html);
        $this->assertStringContainsString('telah dibayar', $html);
    }

    public function test_an_unpaid_invoice_never_claims_to_be_settled(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('telah dibayar', $html);
        $this->assertStringNotContainsString('LUNAS', $html);
    }

    /* ==================================================================
     * And still invents nothing
     * ================================================================== */

    public function test_no_issuer_block_is_printed_when_no_registered_identity_exists(): void
    {
        // With no legal entity configured the block would say "IOMS" and a
        // website -- a third repetition of the name, in a box whose whole
        // purpose is registered detail.
        config(['ioms.legal.entity_name' => null, 'ioms.legal.address' => null]);

        $this->assertStringNotContainsString('Diterbitkan Oleh', $this->render());
    }

    public function test_the_issuer_block_appears_once_a_registered_identity_is_configured(): void
    {
        config([
            'ioms.legal.entity_name' => 'PT Contoh Operasi Nusantara',
            'ioms.legal.address' => 'Jakarta, Indonesia',
        ]);

        $html = $this->render();

        $this->assertStringContainsString('Diterbitkan Oleh', $html);
        $this->assertStringContainsString('PT Contoh Operasi Nusantara', $html);
        $this->assertStringContainsString('Jakarta, Indonesia', $html);
    }

    public function test_there_is_still_no_tax_line(): void
    {
        // IOMS holds no tax registration in configuration. An invoice showing
        // an invented NPWP or an unsupported 0% VAT row is worse than one
        // that stays silent.
        $html = $this->render();

        $this->assertStringNotContainsString('NPWP', $html);
        $this->assertStringNotContainsString('PPN', $html);
    }

    public function test_the_pdf_actually_renders(): void
    {
        // The template is only correct if dompdf can draw it -- a stylesheet
        // it cannot parse fails here rather than in a customer's download.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'pdf.invoice',
            app(InvoiceDocumentService::class)->viewData($this->invoice)
        )->setPaper('a4', 'portrait');

        $output = $pdf->output();

        $this->assertStringStartsWith('%PDF-', $output);
        $this->assertGreaterThan(5000, strlen($output), 'A PDF this small suggests the page rendered empty.');
    }
}
