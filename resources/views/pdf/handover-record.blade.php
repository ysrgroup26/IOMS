<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $record->bast_number }}</title>
    @include('pdf.partials.styles')
</head>
<body>

{{--
    v2.52.0 -- BERITA ACARA SERAH TERIMA (BAST).

    A formal handover instrument, and deliberately NOT shaped like the
    Goods Receipt. A receiving note is a table of quantities; a Berita
    Acara is a narrative statement that two named parties made and
    accepted a handover on a date, followed by the detail and their
    signatures. So this document leads with the parties and the statement,
    and the item table is supporting evidence rather than the point.
--}}

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'Berita Acara Serah Terima',
    'docSubtitle' => $record->typeLabel(),
    'docRefs' => [
        'No.' => $record->bast_number,
        'Tanggal' => $record->handover_date?->format('d M Y'),
        'Status' => strtoupper($record->status),
    ],
])

<div class="section">
    <p style="font-size:9.5px; line-height:1.6;">
        {{-- Locale is pinned on the date itself rather than relying on the
             app locale: this document is written in Indonesian regardless of
             what an individual user set their interface to. --}}
        Pada hari ini, {{ $record->handover_date?->locale('id')->translatedFormat('l') }},
        tanggal {{ $record->handover_date?->locale('id')->translatedFormat('d F Y') }},
        yang bertanda tangan di bawah ini:
    </p>
</div>

<div class="section">
    <table class="data">
        <tbody>
            <tr>
                <td style="width:110px; background:#f8fafc;" class="strong">PIHAK PERTAMA</td>
                <td>
                    <span class="strong">{{ $record->first_party_name }}</span>
                    @if ($record->first_party_position)<div class="muted">{{ $record->first_party_position }}</div>@endif
                    <div class="muted">{{ $record->first_party_organization ?: ($identity['name'] ?? '') }}</div>
                </td>
            </tr>
            <tr>
                <td style="background:#f8fafc;" class="strong">PIHAK KEDUA</td>
                <td>
                    <span class="strong">{{ $record->second_party_name }}</span>
                    @if ($record->second_party_position)<div class="muted">{{ $record->second_party_position }}</div>@endif
                    @if ($record->second_party_organization)<div class="muted">{{ $record->second_party_organization }}</div>@endif
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="section">
    <div class="section-title">Objek Serah Terima</div>
    <table class="kv">
        <tr><td class="k">Perihal</td><td class="v">{{ $record->title }}</td></tr>
        <tr><td class="k">Jenis</td><td class="v">{{ $record->typeLabel() }}</td></tr>
        @if ($record->reference_number)
            <tr><td class="k">Referensi</td><td class="v">{{ $record->reference_number }}</td></tr>
        @endif
    </table>

    @if ($record->scope)
        <div class="note" style="margin-top:6px;">{!! nl2br(e($record->scope)) !!}</div>
    @endif
</div>

@if (! empty($record->items))
    <div class="section">
        <div class="section-title">Rincian</div>
        <table class="data">
            <thead>
                <tr>
                    <th style="width:26px;" class="ctr">No</th>
                    <th>Uraian</th>
                    <th style="width:66px;" class="num">Jumlah</th>
                    <th style="width:52px;">Satuan</th>
                    <th style="width:140px;">Keterangan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($record->items as $i => $item)
                    <tr>
                        <td class="ctr">{{ $i + 1 }}</td>
                        <td>{{ $item['description'] ?? '-' }}</td>
                        <td class="num">{{ $item['quantity'] ?? '' }}</td>
                        <td>{{ $item['unit'] ?? '' }}</td>
                        <td class="muted">{{ $item['notes'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- The operative sentence of the document. --}}
<div class="section avoid-break">
    <div class="section-title">Pernyataan</div>
    <div class="note">{!! nl2br(e($record->statement())) !!}</div>
</div>

@if ($record->notes)
    <div class="section avoid-break">
        <div class="section-title">Catatan</div>
        <div class="note">{!! nl2br(e($record->notes)) !!}</div>
    </div>
@endif

<div class="section">
    <div class="section-title">Tanda Tangan</div>
    @include('pdf.partials.signatures', ['signatures' => [
        [
            'role' => 'Pihak Pertama',
            'name' => $record->first_party_name,
            'position' => $record->first_party_position ?: ($record->first_party_organization ?: ($identity['name'] ?? null)),
            'date' => $record->handover_date?->format('d M Y'),
        ],
        [
            'role' => 'Pihak Kedua',
            'name' => $record->second_party_name,
            'position' => $record->second_party_position ?: $record->second_party_organization,
            'date' => $record->accepted_at?->format('d M Y'),
        ],
        ['role' => 'Mengetahui', 'name' => null],
        ['role' => 'Menyetujui', 'name' => null],
    ]])
</div>

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $record->bast_number,
    'controlNote' => 'Berita Acara ini dibuat dalam rangkap secukupnya dan mempunyai kekuatan hukum yang sama.',
])

</body>
</html>
