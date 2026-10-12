<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf.partials.styles')
    <style>
        /*
            v2.110.0 -- the generic register layout.

            Only two rules are specific to it: a numeric column aligns
            right (so a quantity column reads as a column of numbers), and
            a long register's rows stay compact. Everything else is the
            shared document stylesheet, which is the point -- a register
            printed through the document library must look like every
            other IOMS document, not like a generic table dump.
        */
        .data td, .data th { font-size: 8.5px; padding: 4px 5px; }
        .data .idx { width: 24px; text-align: right; color: #94a3b8; }
    </style>
</head>
<body>

{{--
    v2.110.0 -- ONE PRINTABLE LAYOUT FOR EVERY REGISTERED DOCUMENT.

    Rendered from a `DocumentDefinition` by `DocumentRenderer`, which also
    produces the XLSX and DOCX of the SAME rows. There is no per-module
    copy of this file, and that is deliberate: the Employee Register and
    the Excel export of the Employee Register used to be written from two
    different queries, months apart, and could disagree.

    Values arrive already formatted; this view decides placement, never
    meaning. An empty cell is printed empty -- never as a dash or a zero,
    which would state something the record does not.
--}}

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => $title,
    'docSubtitle' => $description ?? null,
    'docRefs' => array_merge($refs, [
        'Jumlah Baris' => number_format(count($rows), 0, ',', '.'),
        'Dicetak' => $generatedAt->format('d M Y H:i'),
    ]),
])

@if ($rows === [])
    <p class="note">
        Tidak ada data yang cocok dengan filter yang dipilih. Dokumen ini sengaja
        diterbitkan kosong agar tidak terbaca sebagai daftar lengkap.
    </p>
@else
    <table class="data">
        {{-- A real thead, so a long register repeats its header band on
             every page instead of orphaning rows. --}}
        <thead>
            <tr>
                <th class="idx">#</th>
                @foreach ($columns as $column)
                    <th @class(['num' => in_array($column['type'] ?? 'text', ['number', 'decimal', 'currency', 'percent'], true)])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $index => $row)
                <tr>
                    <td class="idx">{{ $index + 1 }}</td>
                    @foreach ($columns as $position => $column)
                        <td @class(['num' => in_array($column['type'] ?? 'text', ['number', 'decimal', 'currency', 'percent'], true)])>{{ $row[$position] ?? '' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $title,
    'controlNote' => 'Dokumen dihasilkan oleh IOMS Document Library',
])

</body>
</html>
