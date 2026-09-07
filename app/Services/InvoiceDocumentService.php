<?php

namespace App\Services;

use App\Models\Invoice;

/**
 * v2.55.0 -- THE SUBSCRIPTION INVOICE, AND WHY IT NEEDED ITS OWN SERVICE.
 *
 * Every other document in IOMS is written BY a tenant: a permit, a purchase
 * order, a work order. `DocumentEngine::identity()` therefore returns the
 * TENANT's company identity, and every PDF puts it on the letterhead. That
 * is correct for all of them.
 *
 * An invoice is the one document that runs the other way. IOMS is the
 * vendor and the tenant is the customer, so putting the tenant's identity
 * on the letterhead would produce a document that appears to be the
 * customer billing themselves. This service supplies the ISSUER identity —
 * IOMS — in exactly the shape `pdf.partials.letterhead` already expects,
 * so the shared letterhead, styles and footer partials are reused
 * unchanged. No second document system, no forked partials.
 *
 * NOTHING IS FABRICATED. The issuer block prints the operator's registered
 * name and address only when `config('ioms.legal')` has them; until then it
 * prints the IOMS brand identity and the billing mailbox, which are true.
 * There is deliberately no tax line: IOMS does not hold a tax registration
 * in configuration, and an invoice that displays an invented NPWP or a 0%
 * VAT line it cannot support is worse than one that stays silent.
 */
class InvoiceDocumentService
{
    /** The issuer letterhead — IOMS, not the customer. Shaped exactly like DocumentEngine::identity(). */
    public function issuerIdentity(): array
    {
        $legal = config('ioms.legal');

        return [
            'name' => config('app.name', 'IOMS'),
            // Only when an operator has actually been configured.
            'legal_name' => $legal['entity_name'] ?: null,
            'logo_url' => null,
            'address' => $legal['address'] ?: null,
            'locality' => null,
            'country' => null,
            'phone' => null,
            'email' => config('ioms.emails.billing'),
            'website' => config('ioms.website'),
            // Deliberately absent: NPWP/NIB are merchant-verification data,
            // not website or document content, and IOMS holds neither.
            'tax_id' => null,
            'business_id' => null,
            'brand_color' => '#1e3a8a',
        ];
    }

    /**
     * Who the invoice is addressed to.
     *
     * An invoice exists in two situations and both must render: BEFORE
     * provisioning it belongs to a TenantRegistration (there is no tenant
     * yet — that is the whole point of the registration record living
     * outside the isolation boundary), and AFTER provisioning it belongs to
     * a tenant. The registration is preferred when present because it
     * carries the billing contact the customer actually entered.
     */
    public function billTo(Invoice $invoice): array
    {
        $registration = $invoice->registration;

        if ($registration) {
            return array_filter([
                'name' => $registration->displayName(),
                'contact' => $registration->contact_name,
                'email' => $registration->billingEmail(),
                'phone' => $registration->contact_phone,
            ]);
        }

        $tenant = $invoice->tenant;

        return array_filter([
            'name' => $tenant?->name,
            'contact' => null,
            'email' => null,
            'phone' => null,
        ]);
    }

    /**
     * The single line an IOMS invoice bills for: one plan, one cycle, one
     * period. IOMS does not sell add-ons or metered usage, so a multi-line
     * items table would be an empty generalisation.
     */
    public function lineItem(Invoice $invoice): array
    {
        $package = $invoice->registration?->package
            ?? $invoice->subscription?->package;

        $cycle = $invoice->registration?->billing_cycle
            ?? $invoice->subscription?->billing_cycle;

        $description = $package
            ? 'IOMS '.$package->name.' — Langganan '.($cycle === 'monthly' ? 'Bulanan' : 'Tahunan')
            : 'Langganan IOMS';

        return [
            'description' => $description,
            'plan' => $package?->name,
            'cycle' => $cycle,
            'period_start' => $invoice->period_start,
            'period_end' => $invoice->period_end,
            'amount' => (float) $invoice->amount,
        ];
    }

    /** A human status word, plus whether it should read as settled. */
    public function statusLabel(Invoice $invoice): array
    {
        return match ($invoice->status) {
            Invoice::STATUS_PAID => ['label' => 'LUNAS', 'paid' => true],
            Invoice::STATUS_ISSUED => ['label' => 'BELUM DIBAYAR', 'paid' => false],
            Invoice::STATUS_OVERDUE => ['label' => 'JATUH TEMPO', 'paid' => false],
            Invoice::STATUS_VOID => ['label' => 'DIBATALKAN', 'paid' => false],
            default => ['label' => strtoupper($invoice->status), 'paid' => false],
        };
    }

    /** Everything `pdf.invoice` needs, assembled in one place so the view stays presentation-only. */
    public function viewData(Invoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'identity' => $this->issuerIdentity(),
            'billTo' => $this->billTo($invoice),
            'item' => $this->lineItem($invoice),
            'statusInfo' => $this->statusLabel($invoice),
            'supportEmail' => config('ioms.emails.support'),
            'billingEmail' => config('ioms.emails.billing'),
        ];
    }
}
