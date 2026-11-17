<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    @include('pdf.partials.styles')
    <style>
        /*
            v2.142.0 (ADR 090) -- THE INVOICE, REDESIGNED AS A BILL.

            A commercial invoice from a company, not a system printout: the
            issuing company and its office lead, the amount due is the
            largest figure on the page, and the footer carries the company's
            contact line instead of a system stamp.

            DOMPDF CONSTRAINTS: tables only, no flex/grid, percentage widths.
            A coloured band is a table cell with a background.
        */
        @page { margin: 0 0 22mm 0; }
        body { font-size: 9.5px; color: #1e293b; }
        .page { padding: 0 14mm; }

        /* ---------- Top band ---------- */
        .band { width: 100%; background-color: #0f2747; }
        .band td { padding: 18px 0 16px; vertical-align: middle; }
        .band .gutter { width: 14mm; padding: 0 !important; }
        .band .mark { width: 46px; }
        .band .mark img { width: 38px; height: 38px; }
        .brand { font-size: 19px; font-weight: bold; color: #ffffff; letter-spacing: 1.4px; line-height: 1.1; }
        .brand-sub { font-size: 6.8px; color: #9fb3cd; letter-spacing: 1.6px; text-transform: uppercase; margin-top: 3px; }
        .doc { text-align: right; }
        .doc-type { font-size: 24px; font-weight: bold; color: #ffffff; letter-spacing: 5px; line-height: 1; }
        .doc-no { font-size: 9px; color: #01c1ed; letter-spacing: 0.8px; margin-top: 5px; }
        .accent { height: 4px; line-height: 4px; font-size: 0; background-color: #01c1ed; }

        /* ---------- Parties ---------- */
        .parties { width: 100%; margin-top: 20px; }
        .parties > tbody > tr > td { vertical-align: top; }
        .eyebrow {
            font-size: 7px; font-weight: bold; text-transform: uppercase;
            letter-spacing: 1.2px; color: #2166c4; padding-bottom: 5px;
        }
        .co-name { font-size: 11.5px; font-weight: bold; color: #0f2747; letter-spacing: 0.3px; }
        .co-brand { font-size: 8.5px; font-weight: bold; color: #2166c4; letter-spacing: 0.6px; margin-top: 1px; }
        .co-line { font-size: 8.5px; color: #475569; line-height: 1.6; margin-top: 4px; }

        .meta { width: 100%; border: 1px solid #dbe3ec; }
        .meta td { padding: 6px 10px; font-size: 8.5px; border-bottom: 1px solid #edf1f6; }
        .meta .k { color: #64748b; width: 46%; }
        .meta .v { color: #0f2747; font-weight: bold; text-align: right; }

        .status {
            display: inline-block; padding: 2px 9px;
            font-size: 8px; font-weight: bold; letter-spacing: 0.9px;
            border: 1px solid #b45309; color: #b45309; background-color: #fffbeb;
        }
        .status.paid { border-color: #15803d; color: #15803d; background-color: #f0fdf4; }
        .status.due { border-color: #b91c1c; color: #b91c1c; background-color: #fef2f2; }

        .billto { width: 100%; margin-top: 18px; background-color: #f5f8fc; border-left: 3px solid #0f2747; }
        .billto td { padding: 11px 14px; vertical-align: top; }

        /* ---------- Items ---------- */
        .items { width: 100%; margin-top: 20px; }
        .items th {
            background-color: #0f2747; color: #ffffff; font-size: 7.5px; font-weight: bold;
            text-transform: uppercase; letter-spacing: 0.9px; padding: 8px 10px; text-align: left;
        }
        .items td { padding: 11px 10px; font-size: 9.5px; border-bottom: 1px solid #e2e8f0; color: #1e293b; }
        .items .num { text-align: right; white-space: nowrap; }
        .items .desc { font-weight: bold; color: #0f2747; }
        .items .note { font-size: 8px; color: #64748b; margin-top: 2px; }

        /* ---------- Totals ---------- */
        .totals-wrap { width: 100%; margin-top: 8px; }
        .totals td { padding: 5px 10px; font-size: 9px; color: #475569; }
        .totals .amt { text-align: right; color: #0f2747; font-weight: bold; }
        .totals .grand td { background-color: #0f2747; color: #ffffff; font-size: 12px; font-weight: bold; padding: 10px; vertical-align: middle; }
        .totals .grand .lbl { font-size: 8px; letter-spacing: 1.2px; text-transform: uppercase; }
        .totals .settled td { font-size: 8.5px; color: #15803d; text-align: right; padding-top: 4px; }

        /* ---------- Payment ---------- */
        .pay { width: 100%; margin-top: 22px; border: 1px solid #dbe3ec; }
        .pay td { padding: 11px 14px; font-size: 8.5px; color: #475569; line-height: 1.65; vertical-align: top; }
        .pay .h { font-size: 7px; font-weight: bold; text-transform: uppercase; letter-spacing: 1.2px; color: #0f2747; padding-bottom: 3px; }
        .pay .side { width: 38%; background-color: #f5f8fc; border-left: 1px solid #dbe3ec; }

        .thanks { margin-top: 22px; font-size: 10px; color: #0f2747; font-weight: bold; }
        .thanks-sub { font-size: 8.5px; color: #64748b; margin-top: 2px; }

        /* ---------- Footer, pinned into the bottom page margin ---------- */
        .foot { position: fixed; bottom: -22mm; left: 0; right: 0; height: 22mm; background-color: #0f2747; }
        .foot-rule { height: 3px; line-height: 3px; font-size: 0; background-color: #01c1ed; }
        .foot-band { width: 100%; background-color: #0f2747; }
        .foot-band td { padding: 10px 0; font-size: 7.5px; color: #c7d3e3; line-height: 1.55; vertical-align: middle; }
        .foot-band .gutter { width: 14mm; padding: 0; }
        .foot-band .r { text-align: right; color: #9fb3cd; }
        .foot-band strong { color: #ffffff; }
    </style>
</head>
<body>

{{--
    v2.55.0 -- the subscription invoice. IOMS is the vendor and the tenant
    the customer, so `$identity` is the ISSUER from InvoiceDocumentService.
    Deliberately no tax line: no tax registration is held in configuration,
    and an invented NPWP or 0% VAT row is worse than silence.
--}}
@php
    $issuerName = $identity['issuer_name'] ?? null;
    $issuerBrand = $identity['issuer_brand'] ?? null;
    $website = $identity['website'] ?? 'iomsuite.com';
    $statusClass = $statusInfo['paid'] ? 'paid' : ($invoice->status === 'overdue' ? 'due' : '');
    $money = fn ($v) => $invoice->currency.' '.number_format($v, 0, ',', '.');
@endphp

{{-- ============================== FOOTER ============================== --}}
<div class="foot">
    <div class="foot-rule">&nbsp;</div>
    <table class="foot-band">
        <tr>
            <td class="gutter"></td>
            <td>
                @if ($issuerName)<strong>{{ strtoupper($issuerName) }}</strong>@if ($issuerBrand) ({{ strtoupper($issuerBrand) }})@endif<br>@endif
                {{ $identity['address'] ?? '' }}
            </td>
            <td class="r" style="width:30%;">{{ $billingEmail }}<br>{{ $website }}</td>
            <td class="gutter"></td>
        </tr>
    </table>
</div>

{{-- ============================== HEADER ============================== --}}
<table class="band">
    <tr>
        <td class="gutter"></td>
        @if (! empty($identity['mark_url']))
            <td class="mark"><img src="{{ $identity['mark_url'] }}" alt="" width="38" height="38"></td>
        @endif
        <td>
            <div class="brand">IOMS</div>
            <div class="brand-sub">Industrial Operations Platform</div>
        </td>
        <td class="doc">
            <div class="doc-type">INVOICE</div>
            <div class="doc-no">{{ $invoice->invoice_number }}</div>
        </td>
        <td class="gutter"></td>
    </tr>
</table>
<div class="accent">&nbsp;</div>

<div class="page">

{{-- ============================= PARTIES ============================== --}}
<table class="parties">
    <tr>
        <td style="width:56%; padding-right:18px;">
            <div class="eyebrow">Diterbitkan Oleh</div>
            @if ($issuerName)
                <div class="co-name">{{ strtoupper($issuerName) }}</div>
                @if ($issuerBrand)<div class="co-brand">{{ strtoupper($issuerBrand) }}</div>@endif
            @else
                <div class="co-name">{{ $identity['name'] ?? 'IOMS' }}</div>
            @endif
            <div class="co-line">
                @if (! empty($identity['address'])){{ $identity['address'] }}<br>@endif
                {{ $billingEmail }} &middot; {{ $website }}
            </div>
        </td>
        <td style="width:44%;">
            <table class="meta">
                <tr><td class="k">Tanggal Terbit</td><td class="v">{{ optional($invoice->created_at)->format('d M Y') ?: '-' }}</td></tr>
                <tr><td class="k">Jatuh Tempo</td><td class="v">{{ optional($invoice->due_date)->format('d M Y') ?: '-' }}</td></tr>
                <tr><td class="k" style="border-bottom:none;">Status</td><td class="v" style="border-bottom:none;"><span class="status {{ $statusClass }}">{{ $statusInfo['label'] }}</span></td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="billto">
    <tr>
        <td>
            <div class="eyebrow">Ditagihkan Kepada</div>
            <div class="co-name">{{ $billTo['name'] ?? '-' }}</div>
            @if (! empty($billTo['contact']) || ! empty($billTo['email']) || ! empty($billTo['phone']))
                <div class="co-line">
                    @if (! empty($billTo['contact'])){{ $billTo['contact'] }}<br>@endif
                    @if (! empty($billTo['email'])){{ $billTo['email'] }}@endif
                    @if (! empty($billTo['email']) && ! empty($billTo['phone'])) &middot; @endif
                    @if (! empty($billTo['phone'])){{ $billTo['phone'] }}@endif
                </div>
            @endif
        </td>
    </tr>
</table>

{{-- ============================== ITEMS ============================== --}}
<table class="items">
    <thead>
        <tr>
            <th style="width:50%;">Deskripsi</th>
            <th style="width:30%;">Periode Layanan</th>
            <th style="width:20%; text-align:right;">Jumlah</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>
                <div class="desc">{{ $item['description'] }}</div>
                @if ($invoice->notes)<div class="note">{{ $invoice->notes }}</div>@endif
            </td>
            <td>
                @if ($item['period_start'] && $item['period_end'])
                    {{ \Illuminate\Support\Carbon::parse($item['period_start'])->format('d M Y') }}
                    &ndash;
                    {{ \Illuminate\Support\Carbon::parse($item['period_end'])->format('d M Y') }}
                @else
                    -
                @endif
            </td>
            <td class="num">{{ $money($item['amount']) }}</td>
        </tr>
    </tbody>
</table>

<table class="totals-wrap">
    <tr>
        <td style="width:54%;"></td>
        <td style="width:46%;">
            <table class="totals" style="width:100%;">
                <tr>
                    <td>Subtotal</td>
                    <td class="amt">{{ $money($item['amount']) }}</td>
                </tr>
                <tr class="grand">
                    <td class="lbl">Total Tagihan</td>
                    <td style="text-align:right;">{{ $money($item['amount']) }}</td>
                </tr>
                @if ($statusInfo['paid'] && $invoice->payment_date)
                    <tr class="settled"><td colspan="2">Dibayar {{ $invoice->payment_date->format('d M Y') }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

{{-- ============================= PAYMENT ============================== --}}
<table class="pay avoid-break">
    <tr>
        <td>
            <div class="h">Informasi Pembayaran</div>
            @if ($statusInfo['paid'])
                Faktur ini telah dibayar. Tidak diperlukan tindakan lebih lanjut.
                @if ($invoice->payment_method || $invoice->payment_reference)
                    <br>
                    @if ($invoice->payment_method)Metode: {{ strtoupper($invoice->payment_method) }}@endif
                    @if ($invoice->payment_method && $invoice->payment_reference) &middot; @endif
                    @if ($invoice->payment_reference)Referensi: {{ $invoice->payment_reference }}@endif
                @endif
            @else
                Bayar secara online melalui <strong>{{ $website }}</strong>, menu <strong>Billing</strong>,
                lalu pilih metode pembayaran yang tersedia. Langganan aktif otomatis setelah pembayaran
                dikonfirmasi oleh penyedia pembayaran kami.
            @endif
        </td>
        <td class="side">
            <div class="h">Butuh Bantuan?</div>
            Tagihan &amp; pembayaran:<br><strong>{{ $billingEmail }}</strong><br>
            Bantuan produk:<br><strong>{{ $supportEmail }}</strong>
        </td>
    </tr>
</table>

<div class="thanks">Terima kasih atas kepercayaan Anda menggunakan IOMS.</div>
<div class="thanks-sub">Faktur elektronik ini sah tanpa tanda tangan.</div>

</div>
</body>
</html>
