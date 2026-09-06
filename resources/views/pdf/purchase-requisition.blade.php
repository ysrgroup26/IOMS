<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $purchaseRequisition->pr_number }}</title>
    @include('pdf.partials.styles')
</head>
<body>

{{--
    v2.51.0 -- Purchase Requisition / Formulir Permintaan Barang (FPB).

    An INTERNAL document: it authorises procurement to go and buy, so it
    carries the requester, the department and cost centre being charged,
    the justification, and the approval boxes. Prices are shown as
    ESTIMATES and labelled as such -- a requisition is not a commitment,
    and presenting an estimate as a price is how a requisition ends up
    being treated as an order.

    Items live in a JSON column on this model rather than a child table
    (see PurchaseRequisition::$casts), so the loop below reads array keys
    defensively.
--}}

@php $items = is_array($purchaseRequisition->items) ? $purchaseRequisition->items : []; @endphp

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'Purchase Requisition / Formulir Permintaan Barang',
    'docSubtitle' => $purchaseRequisition->department?->name,
    'docRefs' => [
        'No.' => $purchaseRequisition->pr_number,
        'Tanggal' => $purchaseRequisition->request_date?->format('d M Y'),
        'Status' => strtoupper(str_replace('_', ' ', $purchaseRequisition->status)),
    ],
])

<table class="section">
    <tr>
        <td style="width:50%; padding-right:10px;">
            <div class="section-title">Pemohon</div>
            <table class="kv">
                <tr><td class="k">Nama</td><td class="v">{{ $purchaseRequisition->requester?->name ?? '-' }}</td></tr>
                <tr><td class="k">Departemen</td><td class="v">{{ $purchaseRequisition->department?->name ?? '-' }}</td></tr>
                <tr><td class="k">Cost Center</td><td class="v">{{ $purchaseRequisition->cost_center ?: '-' }}</td></tr>
            </table>
        </td>
        <td style="width:50%;">
            <div class="section-title">Kebutuhan</div>
            <table class="kv">
                <tr><td class="k">Prioritas</td><td class="v">{{ strtoupper($purchaseRequisition->priority ?: '-') }}</td></tr>
                <tr><td class="k">Dibutuhkan</td><td class="v">{{ $purchaseRequisition->required_date?->format('d M Y') ?? '-' }}</td></tr>
                <tr><td class="k">Proyek</td><td class="v">{{ $purchaseRequisition->project?->name ?? '-' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="section">
    <div class="section-title">Rincian Permintaan</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:26px;" class="ctr">No</th>
                <th>Deskripsi / Spesifikasi</th>
                <th style="width:58px;" class="num">Qty</th>
                <th style="width:46px;">Satuan</th>
                <th style="width:100px;" class="num">Estimasi</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $i => $item)
                <tr>
                    <td class="ctr">{{ $i + 1 }}</td>
                    <td>
                        <span class="strong">{{ $item['description'] ?? ($item['name'] ?? '-') }}</span>
                        @if (! empty($item['specification']))
                            <div class="muted">{{ $item['specification'] }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $item['quantity'] ?? '-' }}</td>
                    <td>{{ $item['unit'] ?? '-' }}</td>
                    <td class="num">
                        @if (isset($item['estimated_price']))
                            {{ number_format((float) $item['estimated_price'], 0, ',', '.') }}
                        @else
                            &mdash;
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="ctr muted">Tidak ada item.</td></tr>
            @endforelse
        </tbody>
        @if ((float) $purchaseRequisition->estimated_total > 0)
            <tfoot>
                <tr>
                    <td colspan="4" class="right">Total Estimasi (IDR)</td>
                    <td class="num">{{ number_format((float) $purchaseRequisition->estimated_total, 0, ',', '.') }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
    <p class="muted" style="margin-top:4px; font-size:7.5px;">
        Nilai di atas merupakan estimasi internal, bukan harga pembelian. Harga final ditetapkan pada Purchase Order.
    </p>
</div>

@if ($purchaseRequisition->justification || $purchaseRequisition->notes)
    <div class="section avoid-break">
        <div class="section-title">Justifikasi</div>
        <div class="note">
            @if ($purchaseRequisition->justification){!! nl2br(e($purchaseRequisition->justification)) !!}@endif
            @if ($purchaseRequisition->notes)
                @if ($purchaseRequisition->justification)<br><br>@endif
                {!! nl2br(e($purchaseRequisition->notes)) !!}
            @endif
        </div>
    </div>
@endif

<div class="section">
    <div class="section-title">Pengesahan</div>
    @include('pdf.partials.signatures', ['signatures' => [
        ['role' => 'Diajukan Oleh', 'name' => $purchaseRequisition->requester?->name, 'date' => $purchaseRequisition->request_date?->format('d M Y')],
        ['role' => 'Diketahui', 'name' => null, 'position' => $purchaseRequisition->department?->name],
        ['role' => 'Disetujui Oleh', 'name' => null],
        ['role' => 'Procurement', 'name' => null],
    ]])
</div>

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $purchaseRequisition->pr_number,
])

</body>
</html>
