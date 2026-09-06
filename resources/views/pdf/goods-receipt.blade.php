<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $goodsReceipt->receipt_number }}</title>
    @include('pdf.partials.styles')
</head>
<body>

{{--
    v2.52.0 -- Goods Receipt / Bukti Penerimaan Barang.

    CORRECTED from v2.51.0, which titled this "Berita Acara Serah Terima
    Barang" and framed it as a Berita Acara. That conflated two different
    business documents.

    This is a WAREHOUSE TRANSACTION: stock arrived, in this quantity, in
    this condition, on this date, against this Purchase Order, and
    inventory moved as a result. It happens many times a week and its
    consequence is a stock level.

    A BAST is a formal handover instrument between two named parties whose
    consequence is contractual, and it now has its own record and its own
    document (see pdf/handover-record.blade.php and HandoverRecord). A
    Goods Receipt may be REFERENCED by a BAST; it is not one.

    Where the receipt came from a Purchase Order, ordered quantity is shown
    beside received quantity so a short delivery is visible on the printed
    page rather than only in the system. Nothing is inferred: if a line has
    no PO link, the ordered column is simply blank.
--}}

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'Goods Receipt / Bukti Penerimaan Barang',
    'docSubtitle' => $goodsReceipt->purchaseOrder?->po_number
        ? 'Terhadap PO '.$goodsReceipt->purchaseOrder->po_number
        : ($goodsReceipt->materialRequest?->request_number ? 'Terhadap MR '.$goodsReceipt->materialRequest->request_number : null),
    'docRefs' => [
        'No.' => $goodsReceipt->receipt_number,
        'Tanggal' => $goodsReceipt->received_date?->format('d M Y'),
    ],
])

<table class="section">
    <tr>
        <td style="width:50%; padding-right:10px;">
            <div class="section-title">Penerimaan</div>
            <table class="kv">
                <tr><td class="k">Diterima Oleh</td><td class="v">{{ $goodsReceipt->receiver?->name ?? '-' }}</td></tr>
                <tr><td class="k">Gudang</td><td class="v">{{ $goodsReceipt->warehouse?->name ?? '-' }}</td></tr>
                <tr><td class="k">Proyek</td><td class="v">{{ $goodsReceipt->project?->name ?? '-' }}</td></tr>
            </table>
        </td>
        <td style="width:50%;">
            <div class="section-title">Referensi</div>
            <table class="kv">
                <tr><td class="k">Purchase Order</td><td class="v">{{ $goodsReceipt->purchaseOrder?->po_number ?? '-' }}</td></tr>
                <tr><td class="k">Vendor</td><td class="v">{{ $goodsReceipt->purchaseOrder?->vendor?->name ?? '-' }}</td></tr>
                <tr><td class="k">Material Request</td><td class="v">{{ $goodsReceipt->materialRequest?->request_number ?? '-' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="section">
    <div class="section-title">Barang Yang Diterima</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:26px;" class="ctr">No</th>
                <th>Deskripsi</th>
                <th style="width:66px;" class="num">Dipesan</th>
                <th style="width:66px;" class="num">Diterima</th>
                <th style="width:52px;">Satuan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($goodsReceipt->items as $i => $item)
                <tr>
                    <td class="ctr">{{ $i + 1 }}</td>
                    <td>
                        <span class="strong">{{ $item->description ?: ($item->item?->name ?? '-') }}</span>
                        @if ($item->item?->item_code)
                            <div class="muted">{{ $item->item->item_code }}</div>
                        @endif
                    </td>
                    <td class="num">
                        @if ($item->purchaseOrderItem)
                            {{ rtrim(rtrim(number_format((float) $item->purchaseOrderItem->quantity, 2, ',', '.'), '0'), ',') }}
                        @else
                            &mdash;
                        @endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $item->quantity_received, 2, ',', '.'), '0'), ',') }}</td>
                    <td>{{ $item->unit }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="ctr muted">Tidak ada item.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($goodsReceipt->notes)
    <div class="section avoid-break">
        <div class="section-title">Catatan Penerimaan</div>
        <div class="note">{!! nl2br(e($goodsReceipt->notes)) !!}</div>
    </div>
@endif

{{-- Deliberately a RECEIVING note, not an acceptance statement. This
     document records what physically arrived and what stock moved; it does
     not assert contractual acceptance -- that is what a BAST is for. --}}
<div class="section avoid-break">
    <div class="note">
        Dokumen ini mencatat penerimaan fisik barang ke gudang pada tanggal
        {{ $goodsReceipt->received_date?->format('d M Y') ?? '-' }} dan menjadi dasar pembaruan stok.
        Selisih jumlah atau kondisi barang yang ditemukan kemudian dicatat sebagai catatan penerimaan tersendiri.
        Serah terima formal antara para pihak dituangkan dalam Berita Acara Serah Terima (BAST) tersendiri.
    </div>
</div>

<div class="section">
    @include('pdf.partials.signatures', ['signatures' => [
        ['role' => 'Pengirim', 'name' => null, 'position' => $goodsReceipt->purchaseOrder?->vendor?->name ?? 'Pengirim'],
        ['role' => 'Penerima Barang', 'name' => $goodsReceipt->receiver?->name, 'date' => $goodsReceipt->received_date?->format('d M Y')],
        ['role' => 'Petugas Gudang', 'name' => null, 'position' => $goodsReceipt->warehouse?->name],
        ['role' => 'Diperiksa', 'name' => null],
    ]])
</div>

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $goodsReceipt->receipt_number,
])

</body>
</html>
