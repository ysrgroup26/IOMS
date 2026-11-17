# ADR 091 — An invoice states the period the payment actually bought

**Status:** Accepted (2026-11-17, the owner). Branch `ticorp/invoice-service-period`.
**Related:** `Invoice::servicePeriod()`, `SubscriptionLifecycleService::applyPaidInvoice()`, `pdf/invoice`,
migration `2027_01_20_100300_an_invoice_records_the_period_it_actually_bought` (additive).

## Context

The owner's own renewal: Starter ended 5 Nov, invoice INV-2026-4-00001 was billed for 5 Nov – 5 Dec,
and was paid on 17 Nov. `extendPeriod()` correctly starts a lapsed renewal on the payment date, so the
subscription runs to 17 Dec, but the invoice still printed 5 Nov – 5 Dec.

## Decision

1. Settlement of a renewal records `service_start`, `service_end` and, when it was paid after the
   lapse, `previous_period_end` on the invoice.
2. `Invoice::servicePeriod()` is what the invoice PDF and the Billing list show. An invoice settled
   before these columns existed is derived the same way (paid after its billed start: the period runs
   from the payment date for the billed length; the billed start is when the previous period ended),
   so no data migration is needed and the owner's invoice shows 17 Nov – 17 Dec.
3. A shifted invoice carries one line: "Masa aktif sebelumnya berakhir 05 Nov 2026. Pembayaran
   diterima 17 Nov 2026, sehingga masa aktif baru dihitung sejak tanggal pembayaran." An early
   renewal keeps its billed period and no note.

## Tests

`InvoiceServicePeriodTest` (3): the owner's case end to end including the PDF; a legacy paid invoice;
an early renewal unchanged. Full suite 1828 passed.
