<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    @include('pdf.partials.styles')
    <style>
        /*
            v2.81.0 -- THE INVOICE HEADER IS THE INVOICE'S OWN.

            This document used `pdf.partials.letterhead`, which is built to
            print a TENANT's identity: logo, company name, legal name. Fed
            the issuer identity it produced the IOMS lockup -- artwork that
            already reads "IOMS" -- directly beside the text "IOMS", so the
            document announced itself twice, and the reused rules gave a
            subscription invoice the same austere controlled-document
            header as a permit to work.

            The shared letterhead is deliberately NOT changed: every tenant
            document depends on it. This file carries its own header
            instead, matching the identity customers already recognise from
            IOMS email (`emails/layout.blade.php`): a navy band, the mark,
            the name as type with the descriptor beneath it, and a cyan
            rule under the band.

            DOMPDF CONSTRAINTS still apply and still shape everything:
            tables, no flex, no grid, percentage widths. A coloured "band"
            is a table row with a background, because that is what renders
            identically in every PDF viewer and on paper.
        */

        /* ---------------- Header band ---------------- */
        .inv-head { width: 100%; background-color: #0f2747; }
        .inv-head td { padding: 13px 14px; vertical-align: middle; }

        /* Fixed width so the mark cannot be stretched by its neighbour. */
        .inv-mark { width: 40px; padding-right: 0 !important; }
        .inv-mark img { width: 34px; height: 34px; }

        .inv-brand { font-size: 17px; font-weight: bold; color: #ffffff; letter-spacing: 1.2px; line-height: 1.1; }
        .inv-brand-sub { font-size: 6.6px; color: #9fb3cd; letter-spacing: 1.5px; text-transform: uppercase; margin-top: 3px; }

        .inv-doc { text-align: right; }
        .inv-doc-type { font-size: 16px; font-weight: bold; color: #ffffff; letter-spacing: 3px; line-height: 1.1; }
        .inv-doc-no { font-size: 8.5px; color: #9fb3cd; letter-spacing: 0.6px; margin-top: 3px; }

        /* The tone rule under the band -- the same 3px accent the email
           layout uses to separate the header from the message. */
        .inv-accent { height: 3px; line-height: 3px; font-size: 0; background-color: #01c1ed; margin-bottom: 14px; }

        /* ---------------- Meta strip ---------------- */
        /* Issue date, due date and status, read in one line across the top
           of the body -- the three facts somebody opening an invoice looks
           for before anything else. */
        .inv-meta { width: 100%; margin-bottom: 14px; }
        .inv-meta td {
            border: 1px solid #e2e8f0; padding: 7px 10px; width: 33.33%;
        }
        .inv-meta .lbl {
            font-size: 7px; text-transform: uppercase; letter-spacing: 0.9px;
            color: #94a3b8; padding-bottom: 2px;
        }
        .inv-meta .val { font-size: 10px; font-weight: bold; color: #0f2747; }

        /* ---------------- Status ---------------- */
        .inv-status {
            display: inline-block; padding: 3px 10px;
            font-size: 9px; font-weight: bold; letter-spacing: 0.8px;
            border: 1px solid #94a3b8; color: #334155; background-color: #f8fafc;
        }
        .inv-status.paid { border-color: #15803d; color: #15803d; background-color: #f0fdf4; }
        .inv-status.due { border-color: #b91c1c; color: #b91c1c; background-color: #fef2f2; }

        /* ---------------- Parties ---------------- */
        .inv-party { width: 100%; margin-bottom: 14px; }
        .inv-party > tbody > tr > td { width: 50%; vertical-align: top; }
        .inv-party .pad-r { padding-right: 16px; }
        .inv-party-title {
            font-size: 7px; font-weight: bold; text-transform: uppercase;
            letter-spacing: 1px; color: #2166c4; padding-bottom: 4px;
        }
        .inv-party-name { font-size: 11px; font-weight: bold; color: #0f2747; }
        .inv-party-line { font-size: 8.5px; color: #475569; line-height: 1.6; }

        /* ---------------- Totals ---------------- */
        .inv-sum { width: 52%; margin-top: 10px; }
        .inv-sum td { padding: 4px 0; font-size: 9.5px; color: #475569; }
        .inv-sum .amt { text-align: right; color: #0f2747; font-weight: bold; }
        .inv-sum .grand td {
            font-size: 12.5px; font-weight: bold; color: #0f2747;
            border-top: 2px solid #0f2747; padding-top: 7px;
        }
        .inv-sum .settled td { font-size: 8.5px; font-weight: normal; color: #15803d; padding-top: 2px; }

        /* ---------------- Payment information ---------------- */
        .inv-pay {
            border: 1px solid #e2e8f0; border-left: 3px solid #2166c4;
            background-color: #f8fafc;
            padding: 9px 11px; font-size: 8.5px; color: #475569; line-height: 1.65;
        }
        .inv-pay .h {
            font-size: 7px; font-weight: bold; text-transform: uppercase;
            letter-spacing: 1px; color: #0f2747; padding-bottom: 3px;
        }
    </style>
</head>
<body>

{{--
    v2.55.0 -- the subscription invoice.

    THE ONE DOCUMENT IN IOMS THAT RUNS THE OTHER WAY. Every other PDF is
    written BY a tenant, so it carries the tenant's letterhead. Here IOMS is
    the vendor and the tenant is the customer, so `$identity` is the ISSUER
    identity from InvoiceDocumentService -- not DocumentEngine::identity(),
    which would print the customer billing themselves.

    Deliberately no tax line. IOMS holds no tax registration in
    configuration, and an invoice showing an invented NPWP or an unsupported
    0% VAT row is worse than one that says nothing. Add it when the
    registration genuinely exists.

    v2.81.0 -- redesigned. Same data, same service, same business logic;
    the header, hierarchy and spacing are this document's own. See the
    stylesheet above for why the shared letterhead was left alone.
--}}

{{-- ============================== HEADER ============================== --}}
<table class="inv-head">
    <tr>
        @if (! empty($identity['mark_url']))
            <td class="inv-mark">
                {{-- Width and height are both declared so the mark keeps its
                     1:1 proportion no matter what the cell does. --}}
                <img src="{{ $identity['mark_url'] }}" alt="" width="34" height="34">
            </td>
        @endif
        <td>
            <div class="inv-brand">IOMS</div>
            <div class="inv-brand-sub">Industrial Operations Platform</div>
        </td>
        <td class="inv-doc">
            <div class="inv-doc-type">INVOICE</div>
            <div class="inv-doc-no">{{ $invoice->invoice_number }}</div>
        </td>
    </tr>
</table>
<div class="inv-accent">&nbsp;</div>

{{-- ============================== META =============================== --}}
<table class="inv-meta">
    <tr>
        <td>
            <div class="lbl">Tanggal Terbit</div>
            <div class="val">{{ optional($invoice->created_at)->format('d M Y') ?: '-' }}</div>
        </td>
        <td>
            <div class="lbl">Jatuh Tempo</div>
            <div class="val">{{ optional($invoice->due_date)->format('d M Y') ?: '-' }}</div>
        </td>
        <td>
            <div class="lbl">Status</div>
            <div style="padding-top:1px;">
                <span class="inv-status {{ $statusInfo['paid'] ? 'paid' : ($invoice->status === 'overdue' ? 'due' : '') }}">{{ $statusInfo['label'] }}</span>
            </div>
        </td>
    </tr>
</table>

{{-- ============================= PARTIES ============================== --}}
@php
    /*
     * The issuer column appears only when there is a REGISTERED identity to
     * print. With none configured it would say "IOMS" and a website -- a
     * third repetition of the name the header already carries twice over,
     * in a box whose whole purpose is registered detail. The billing mailbox
     * is stated in Informasi Pembayaran either way, so nothing is lost.
     *
     * Nothing is invented to fill it: see InvoiceDocumentService.
     */
    $showIssuer = ! empty($identity['legal_name']) || ! empty($identity['address']);
@endphp
<table class="inv-party">
    <tr>
        <td class="{{ $showIssuer ? 'pad-r' : '' }}" @unless ($showIssuer) colspan="2" @endunless>
            <div class="inv-party-title">Ditagihkan Kepada</div>
            <div class="inv-party-name">{{ $billTo['name'] ?? '-' }}</div>
            <div class="inv-party-line">
                @if (! empty($billTo['contact'])){{ $billTo['contact'] }}<br>@endif
                @if (! empty($billTo['email'])){{ $billTo['email'] }}<br>@endif
                @if (! empty($billTo['phone'])){{ $billTo['phone'] }}@endif
            </div>
        </td>
        @if ($showIssuer)
            <td>
                <div class="inv-party-title">Diterbitkan Oleh</div>
                <div class="inv-party-name">{{ $identity['legal_name'] ?: ($identity['name'] ?? 'IOMS') }}</div>
                <div class="inv-party-line">
                    @if (! empty($identity['address'])){{ $identity['address'] }}<br>@endif
                    @if (! empty($identity['website'])){{ $identity['website'] }}<br>@endif
                    {{ $billingEmail }}
                </div>
            </td>
        @endif
    </tr>
</table>

{{-- ============================== ITEMS ============================== --}}
<div class="section">
    <table class="data">
        <thead>
            <tr>
                <th style="width:52%;">Deskripsi</th>
                <th style="width:28%;">Periode Layanan</th>
                <th class="num" style="width:20%;">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <span class="strong">{{ $item['description'] }}</span>
                    @if ($invoice->notes)
                        <br><span class="muted">{{ $invoice->notes }}</span>
                    @endif
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
                <td class="num">{{ $invoice->currency }} {{ number_format($item['amount'], 0, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    {{-- Totals sit right, under the amount column they total. A one-line
         invoice still gets a subtotal row: the eye follows the column down
         to the rule, and a bare figure floating under a table reads as an
         afterthought. --}}
    <table style="width:100%; margin-top:0;">
        <tr>
            <td style="width:48%;"></td>
            <td style="width:52%;">
                <table class="inv-sum">
                    <tr>
                        <td>Subtotal</td>
                        <td class="amt">{{ $invoice->currency }} {{ number_format($item['amount'], 0, ',', '.') }}</td>
                    </tr>
                    <tr class="grand">
                        <td>Total</td>
                        <td class="amt">{{ $invoice->currency }} {{ number_format($item['amount'], 0, ',', '.') }}</td>
                    </tr>
                    @if ($statusInfo['paid'] && $invoice->payment_date)
                        <tr class="settled">
                            <td colspan="2" class="right">Dibayar {{ $invoice->payment_date->format('d M Y') }}</td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</div>

{{-- ============================= PAYMENT ============================== --}}
<div class="section avoid-break">
    <div class="inv-pay">
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
            Pembayaran dilakukan melalui halaman pembayaran IOMS menggunakan penyedia pembayaran resmi kami.
            Langganan menjadi aktif setelah pembayaran dikonfirmasi kepada server kami oleh penyedia pembayaran.
        @endif
        <br>
        Pertanyaan mengenai faktur ini: {{ $billingEmail }} &middot; Bantuan penggunaan produk: {{ $supportEmail }}
    </div>
</div>

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $invoice->invoice_number,
    'controlNote' => 'Dokumen ini dihasilkan otomatis dan sah tanpa tanda tangan basah.',
])

</body>
</html>
