<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    @include('pdf.partials.styles')
    <style>
        /* The only invoice-specific rules. Everything else -- letterhead,
           section titles, the `data` table, the footer -- comes from the
           shared partials, so an invoice looks like the rest of IOMS's
           documents rather than like a separate product.

           Table-based and flex-free on purpose: dompdf renders neither
           flexbox nor grid (see partials/styles.blade.php). */
        .inv-status {
            display: inline-block;
            padding: 3px 9px;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 0.6px;
            border: 1px solid #94a3b8;
            color: #334155;
        }
        .inv-status.paid { border-color: #15803d; color: #15803d; }
        .inv-total td {
            font-size: 12px;
            font-weight: bold;
            color: #0f2747;
            border-top: 2px solid #0f2747;
            padding-top: 6px;
        }
        .inv-pay { border: 1px solid #cbd5e1; padding: 8px 10px; font-size: 8.5px; color: #475569; line-height: 1.6; }
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
--}}

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'Invoice / Faktur Langganan',
    'docSubtitle' => $item['plan'] ? 'IOMS '.$item['plan'] : null,
    'docRefs' => [
        'No.' => $invoice->invoice_number,
        'Tanggal' => optional($invoice->created_at)->format('d M Y'),
        'Jatuh Tempo' => optional($invoice->due_date)->format('d M Y'),
    ],
])

<table class="section">
    <tr>
        <td style="width:58%; padding-right:12px;">
            <div class="section-title">Ditagihkan Kepada</div>
            <table class="kv">
                <tr><td class="k">Organisasi</td><td class="v">{{ $billTo['name'] ?? '-' }}</td></tr>
                @if (! empty($billTo['contact']))
                    <tr><td class="k">Kontak</td><td class="v">{{ $billTo['contact'] }}</td></tr>
                @endif
                @if (! empty($billTo['email']))
                    <tr><td class="k">Email</td><td class="v">{{ $billTo['email'] }}</td></tr>
                @endif
                @if (! empty($billTo['phone']))
                    <tr><td class="k">Telepon</td><td class="v">{{ $billTo['phone'] }}</td></tr>
                @endif
            </table>
        </td>
        <td style="width:42%;">
            <div class="section-title">Status</div>
            <div style="padding-top:2px;">
                <span class="inv-status {{ $statusInfo['paid'] ? 'paid' : '' }}">{{ $statusInfo['label'] }}</span>
            </div>
            <table class="kv" style="margin-top:6px;">
                @if ($invoice->payment_date)
                    <tr><td class="k">Dibayar</td><td class="v">{{ $invoice->payment_date->format('d M Y') }}</td></tr>
                @endif
                @if ($invoice->payment_method)
                    <tr><td class="k">Metode</td><td class="v">{{ strtoupper($invoice->payment_method) }}</td></tr>
                @endif
                @if ($invoice->payment_reference)
                    <tr><td class="k">Referensi</td><td class="v">{{ $invoice->payment_reference }}</td></tr>
                @endif
            </table>
        </td>
    </tr>
</table>

<div class="section">
    <div class="section-title">Rincian</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:52%;">Deskripsi</th>
                <th style="width:28%;">Periode</th>
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

    <table style="margin-top:8px;">
        <tr class="inv-total">
            <td style="width:70%;" class="right">Total</td>
            <td class="right">{{ $invoice->currency }} {{ number_format($item['amount'], 0, ',', '.') }}</td>
        </tr>
    </table>
</div>

<div class="section avoid-break">
    <div class="section-title">Pembayaran</div>
    <div class="inv-pay">
        @if ($statusInfo['paid'])
            Faktur ini telah dibayar. Tidak diperlukan tindakan lebih lanjut.
        @else
            Pembayaran dilakukan melalui halaman pembayaran IOMS menggunakan penyedia pembayaran resmi kami.
            Langganan menjadi aktif setelah pembayaran dikonfirmasi kepada server kami oleh penyedia pembayaran.
        @endif
        <br>
        Pertanyaan mengenai faktur ini: {{ $billingEmail }}. Bantuan penggunaan produk: {{ $supportEmail }}.
    </div>
</div>

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $invoice->invoice_number,
    'controlNote' => 'Dokumen ini dihasilkan otomatis dan sah tanpa tanda tangan basah.',
])

</body>
</html>
