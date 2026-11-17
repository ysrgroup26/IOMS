# ADR 090 — The invoice names its issuer; renewals offer Duitku's channels; one email palette

**Status:** Accepted (2026-11-17, the owner). Branch `ticorp/billing-docs`. Amends ADR 085 for the invoice PDF only.

## Context

1. With Duitku Production live, the owner opened Billing → Pay for a renewal and found no way to pay.
   The server already asked Duitku for its channels and sent them (`payment.methods`), but
   `Subscription/Checkout.jsx` only knew Midtrans Snap: with no Snap script it fell back to a
   hosted-page link that does not exist before a channel is chosen, so the page rendered nothing.
2. The owner wants the invoice to read as a company's bill: issued by **PT Tahada Vistara Bersama
   (Tahada Group)** at **Ad Premier Office Park Lt.5 Suite 6, Jl. TB Simatupang, Ps. Minggu, Kota
   Jakarta Selatan, DKI Jakarta 12550** (corrected by the owner the same day), without the "Generated … IOMS" system stamp.
3. Transactional email used a different accent per purpose (purple security, teal billing) next to
   a navy header and a lighter blue button; in Gmail's dark mode the result looked disjointed.

## Decision

1. The renewal checkout renders the provider's channel list and posts the choice to the existing
   `subscription.pay.start` route (server-side re-validation unchanged), exactly as the onboarding
   checkout already did.
2. `config('ioms.invoice_issuer')` (env-overridable) names the issuing company, brand and office, and
   is read by the invoice PDF only. ADR 085 still holds for every public page: the payment
   provider's review was about the website. The invoice is redesigned: navy band, issuer and meta,
   bill-to panel, items, a navy "Total Tagihan" bar, payment and help panel, and a company footer.
   Still no tax line.
3. Email: one band colour (brand cyan; red only for a destructive notice), eyebrow blue except
   success/danger, buttons in the header navy with a cyan underline, and Gmail dark-mode guards
   (gradient backgrounds and blend-mode text) so the header stays navy.

## Tests

`InvoiceDocumentTest` (issuer named, configurable, absent from public pages, no system stamp, PDF
renders). Full suite 1825 passed. The PDF was rendered and inspected as an image.
