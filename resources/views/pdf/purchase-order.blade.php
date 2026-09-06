<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $purchaseOrder->po_number }}</title>
    @include('pdf.partials.styles')
</head>
<body>

{{--
    v2.51.0 -- Purchase Order / Surat Pesanan.

    The first document built entirely on the shared IOMS document system:
    letterhead, styles, signature block and control footer all come from
    pdf/partials, so this file contains only what is genuinely specific to
    a purchase order.

    This is an EXTERNAL document -- it goes to a vendor -- which is why it
    carries the buyer's full identity, payment and delivery terms, a
    line-item table that totals correctly, and signature boxes for the
    functions that authorise a commitment to spend.
--}}

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'Purchase Order / Surat Pesanan',
    'docSubtitle' => $purchaseOrder->vendor?->name ? 'Kepada: '.$purchaseOrder->vendor->name : null,
    'docRefs' => [
        'No.' => $purchaseOrder->po_number,
        'Tanggal' => $purchaseOrder->po_date?->format('d M Y'),
        'Status' => strtoupper(str_replace('_', ' ', $purchaseOrder->status)),
    ],
])

<table class="section">
    <tr>
        <td style="width:50%; padding-right:10px;">
            <div class="section-title">Vendor</div>
            <table class="kv">
                <tr><td class="k">Nama</td><td class="v">{{ $purchaseOrder->vendor?->name ?? '-' }}</td></tr>
                <tr><td class="k">Kode Vendor</td><td class="v">{{ $purchaseOrder->vendor?->vendor_code ?? '-' }}</td></tr>
                <tr><td class="k">Syarat Bayar</td><td class="v">{{ $purchaseOrder->payment_terms ?: '-' }}</td></tr>
            </table>
        </td>
        <td style="width:50%;">
            <div class="section-title">Pengiriman</div>
            <table class="kv">
                <tr><td class="k">Tanggal Kirim</td><td class="v">{{ $purchaseOrder->delivery_date?->format('d M Y') ?? '-' }}</td></tr>
                <tr><td class="k">Lokasi</td><td class="v">{{ $purchaseOrder->delivery_location ?: '-' }}</td></tr>
                <tr><td class="k">Proyek</td><td class="v">{{ $purchaseOrder->project?->name ?? '-' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="section">
    <div class="section-title">Rincian Barang / Jasa</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:26px;" class="ctr">No</th>
                <th>Deskripsi</th>
                <th style="width:58px;" class="num">Qty</th>
                <th style="width:46px;">Satuan</th>
                <th style="width:86px;" class="num">Harga</th>
                <th style="width:92px;" class="num">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($purchaseOrder->items as $i => $item)
                <tr>
                    <td class="ctr">{{ $i + 1 }}</td>
                    <td>
                        <span class="strong">{{ $item->description }}</span>
                        @if ($item->specification)
                            <div class="muted">{{ $item->specification }}</div>
                        @endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td>{{ $item->unit }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 0, ',', '.') }}</td>
                    <td class="num">{{ number_format((float) $item->line_total, 0, ',', '.') }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="ctr muted">Tidak ada item.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" class="right">Subtotal</td>
                <td class="num">{{ number_format((float) $purchaseOrder->subtotal, 0, ',', '.') }}</td>
            </tr>
            @if ((float) $purchaseOrder->discount_amount > 0)
                <tr><td colspan="5" class="right">Diskon</td><td class="num">({{ number_format((float) $purchaseOrder->discount_amount, 0, ',', '.') }})</td></tr>
            @endif
            @if ((float) $purchaseOrder->tax_amount > 0)
                <tr><td colspan="5" class="right">PPN</td><td class="num">{{ number_format((float) $purchaseOrder->tax_amount, 0, ',', '.') }}</td></tr>
            @endif
            @if ((float) $purchaseOrder->shipping_amount > 0)
                <tr><td colspan="5" class="right">Pengiriman</td><td class="num">{{ number_format((float) $purchaseOrder->shipping_amount, 0, ',', '.') }}</td></tr>
            @endif
            @if ((float) $purchaseOrder->other_charges > 0)
                <tr><td colspan="5" class="right">Biaya Lain</td><td class="num">{{ number_format((float) $purchaseOrder->other_charges, 0, ',', '.') }}</td></tr>
            @endif
            <tr>
                <td colspan="5" class="right">TOTAL ({{ $purchaseOrder->currency ?: 'IDR' }})</td>
                <td class="num">{{ number_format((float) $purchaseOrder->grand_total, 0, ',', '.') }}</td>
            </tr>
        </tfoot>
    </table>
</div>

@if ($purchaseOrder->terms_conditions || $purchaseOrder->notes)
    <div class="section avoid-break">
        <div class="section-title">Syarat &amp; Catatan</div>
        <div class="note">
            @if ($purchaseOrder->terms_conditions)
                {!! nl2br(e($purchaseOrder->terms_conditions)) !!}
            @endif
            @if ($purchaseOrder->notes)
                @if ($purchaseOrder->terms_conditions)<br><br>@endif
                {!! nl2br(e($purchaseOrder->notes)) !!}
            @endif
        </div>
    </div>
@endif

<div class="section">
    <div class="section-title">Pengesahan</div>
    @include('pdf.partials.signatures', ['signatures' => [
        ['role' => 'Dibuat Oleh', 'name' => $purchaseOrder->requester?->name, 'date' => $purchaseOrder->po_date?->format('d M Y')],
        ['role' => 'Disetujui Oleh', 'name' => $purchaseOrder->approver?->name],
        ['role' => 'Diterbitkan Oleh', 'name' => $purchaseOrder->issuer?->name, 'date' => $purchaseOrder->issued_at?->format('d M Y')],
        ['role' => 'Vendor', 'name' => null, 'position' => $purchaseOrder->vendor?->name],
    ]])
</div>

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $purchaseOrder->po_number,
    'controlNote' => 'Dokumen ini sah tanpa tanda tangan basah bila diterbitkan melalui IOMS.',
])

</body>
</html>
