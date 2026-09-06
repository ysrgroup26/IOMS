<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $workOrder->wo_number }}</title>
    @include('pdf.partials.styles')
</head>
<body>

{{--
    v2.51.0 -- Work Order / Surat Perintah Kerja (SPK).

    An instruction to carry out work: what, on which asset, by whom, when
    planned and when actually done. The completion section and its
    signature boxes are what turn it into the record an auditor asks for
    afterwards -- so both halves live on one sheet rather than the work
    order and its completion note being separate pieces of paper.

    Deliberately NOT a contract SPK. In Indonesian procurement, "SPK" is
    also used for a small-value work contract with a vendor; this document
    is the internal maintenance/operations work instruction that
    WorkOrder actually models, and it is titled so an operator reading it
    knows which of the two they are holding.
--}}

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'Work Order / Surat Perintah Kerja',
    'docSubtitle' => $workOrder->asset?->name ? 'Asset: '.$workOrder->asset->name : null,
    'docRefs' => [
        'No.' => $workOrder->wo_number,
        'Tanggal' => $workOrder->planned_date?->format('d M Y'),
        'Status' => strtoupper(str_replace('_', ' ', $workOrder->status)),
    ],
])

<table class="section">
    <tr>
        <td style="width:50%; padding-right:10px;">
            <div class="section-title">Objek Pekerjaan</div>
            <table class="kv">
                <tr><td class="k">Asset</td><td class="v">{{ $workOrder->asset?->name ?? '-' }}</td></tr>
                <tr><td class="k">Kode Asset</td><td class="v">{{ $workOrder->asset?->asset_code ?? '-' }}</td></tr>
                <tr><td class="k">Jenis</td><td class="v">{{ strtoupper(str_replace('_', ' ', $workOrder->maintenance_type ?: '-')) }}</td></tr>
            </table>
        </td>
        <td style="width:50%;">
            <div class="section-title">Pelaksanaan</div>
            <table class="kv">
                <tr><td class="k">Teknisi</td><td class="v">{{ $workOrder->technician?->name ?? '-' }}</td></tr>
                <tr><td class="k">Rencana</td><td class="v">{{ $workOrder->planned_date?->format('d M Y') ?? '-' }}</td></tr>
                <tr><td class="k">Realisasi</td><td class="v">{{ $workOrder->actual_date?->format('d M Y') ?? '-' }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="section">
    <div class="section-title">Uraian Pekerjaan</div>
    <div class="note">{!! nl2br(e($workOrder->work_description ?: '-')) !!}</div>
</div>

@if ($workOrder->relationLoaded('spareParts') && $workOrder->spareParts->isNotEmpty())
    <div class="section">
        <div class="section-title">Suku Cadang Digunakan</div>
        <table class="data">
            <thead>
                <tr>
                    <th style="width:26px;" class="ctr">No</th>
                    <th>Deskripsi</th>
                    <th style="width:66px;" class="num">Qty</th>
                    <th style="width:52px;">Satuan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($workOrder->spareParts as $i => $part)
                    <tr>
                        <td class="ctr">{{ $i + 1 }}</td>
                        <td>{{ $part->description ?: ($part->item?->name ?? '-') }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $part->quantity, 2, ',', '.'), '0'), ',') }}</td>
                        <td>{{ $part->unit ?? '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<div class="section avoid-break">
    <div class="section-title">Hasil / Penyelesaian</div>
    <div class="note" style="min-height:44px;">
        {!! $workOrder->completion_notes ? nl2br(e($workOrder->completion_notes)) : '&nbsp;' !!}
    </div>
</div>

<div class="section">
    <div class="section-title">Pengesahan</div>
    @include('pdf.partials.signatures', ['signatures' => [
        ['role' => 'Diterbitkan Oleh', 'name' => $workOrder->creator?->name, 'date' => $workOrder->planned_date?->format('d M Y')],
        ['role' => 'Dilaksanakan Oleh', 'name' => $workOrder->technician?->name, 'date' => $workOrder->actual_date?->format('d M Y')],
        ['role' => 'Diperiksa Oleh', 'name' => null],
        ['role' => 'Disetujui Oleh', 'name' => null],
    ]])
</div>

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $workOrder->wo_number,
])

</body>
</html>
