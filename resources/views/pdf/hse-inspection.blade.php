<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $inspection->inspection_number }}</title>
    @include('pdf.partials.styles')
    <style>
        /*
            v2.110.0 -- HSE Inspection Report, document-only additions.

            The shared stylesheet above already carries the letterhead,
            section titles, `.data` tables, signature boxes and footer, and
            it is included as a partial (which emits its own <style>) --
            NOT nested inside this one, which would produce an invalid
            nested <style> and silently lose every rule in it.

            Only what is genuinely specific to an inspection is declared
            here: the per-item verdict chip and the overall-result banner.
        */
        .verdict {
            display: inline-block; padding: 1px 6px; border-radius: 2px;
            font-size: 7.5px; font-weight: bold; text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .verdict-pass { background: #dcfce7; color: #166534; }
        .verdict-fail { background: #fee2e2; color: #991b1b; }
        .verdict-na   { background: #f1f5f9; color: #475569; }

        .result-banner {
            border: 1px solid #cbd5e1; border-left-width: 4px;
            background: #f8fafc; padding: 6px 9px; margin: 10px 0;
        }
        .result-pass { border-left-color: #16a34a; background: #f0fdf4; }
        .result-fail { border-left-color: #dc2626; background: #fef2f2; }
        .result-unset { border-left-color: #94a3b8; }
        .result-label { font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.8px; color: #64748b; }
        .result-value { font-size: 12px; font-weight: bold; color: #0f2747; }
        .result-note { font-size: 8px; color: #64748b; }

        .prose { font-size: 9px; color: #334155; line-height: 1.45; white-space: pre-wrap; }
    </style>
</head>
<body>

{{--
    v2.110.0 -- Laporan Inspeksi HSE / HSE Inspection Report.

    The paper record of one inspection: what was inspected, by whom, every
    checklist line with its verdict, the overall result, and the corrective
    actions it raised. An inspection that lived only in the database could
    not be filed, signed, or handed to a client auditor; this is that
    document.

    THE CHECKLIST IS WHATEVER THE INSPECTION STORED. `checklist_items` is
    a JSON array whose shape is decided by the form and by any checklist
    template loaded into it (HseInspectionController::store validates
    `item` / `result` / `remarks`, and templates may carry other key
    names), so every key is read defensively and a line with no verdict
    renders as "N/A" -- never as a pass. An unanswered checklist line that
    printed as answered would be a false safety record.

    Dompdf-safe by the same constraints as every other IOMS document: no
    flexbox, no grid, table-based layout, border-collapse declared. The
    checklist uses a real `thead` so a long inspection repeats its header
    band on every page instead of orphaning rows.
--}}

@php
    $typeLabel = $inspection->inspection_type
        ? ucwords(str_replace('_', ' ', $inspection->inspection_type))
        : null;

    $items = is_array($inspection->checklist_items) ? $inspection->checklist_items : [];

    /*
     * Reads one checklist line without assuming which key the writer
     * used. `remarks` is the key HseInspectionController validates;
     * `note`/`notes`/`remark` cover checklist templates and imported
     * rows. Nothing is invented: a key that is absent stays null.
     */
    $readItem = function ($item): array {
        if (! is_array($item)) {
            return ['label' => (string) $item, 'verdict' => null, 'note' => null];
        }

        return [
            'label' => $item['item'] ?? $item['label'] ?? $item['name'] ?? null,
            'verdict' => $item['result'] ?? $item['status'] ?? $item['verdict'] ?? null,
            'note' => $item['remarks'] ?? $item['remark'] ?? $item['notes'] ?? $item['note'] ?? null,
        ];
    };

    /*
     * The stored token, made readable. `na` must print as "N/A" and not
     * as "NA", which reads like a word rather than a non-applicable line.
     */
    $verdictLabel = function ($verdict): string {
        $v = strtolower(trim((string) $verdict));

        if ($v === '' ) {
            return 'N/A';
        }

        return in_array($v, ['na', 'n/a'], true) ? 'N/A' : str_replace('_', ' ', $v);
    };

    $verdictClass = function ($verdict): string {
        $v = strtolower(trim((string) $verdict));

        if (in_array($v, ['ok', 'pass', 'sesuai', 'yes', 'ya'], true)) {
            return 'verdict-pass';
        }

        if (in_array($v, ['not_ok', 'fail', 'tidak_sesuai', 'tidak', 'no'], true)) {
            return 'verdict-fail';
        }

        return 'verdict-na';
    };

    $result = strtolower(trim((string) $inspection->overall_result));
    $resultClass = match (true) {
        in_array($result, ['pass', 'lulus', 'ok'], true) => 'result-pass',
        in_array($result, ['fail', 'tidak_lulus', 'not_ok'], true) => 'result-fail',
        default => 'result-unset',
    };

    // Only counted, never derived into a verdict the record does not hold.
    $notOk = collect($items)->filter(fn ($i) => $verdictClass($readItem($i)['verdict']) === 'verdict-fail')->count();

    $actions = $inspection->relationLoaded('correctiveActions') ? $inspection->correctiveActions : collect();
@endphp

@include('pdf.partials.letterhead', [
    'identity' => $identity,
    'docTitle' => 'HSE Inspection Report / Laporan Inspeksi HSE',
    'docSubtitle' => $typeLabel ? 'Inspection Type: '.$typeLabel : null,
    'docRefs' => [
        'No. Dokumen' => $inspection->inspection_number,
        'Tanggal Inspeksi' => optional($inspection->inspection_date)->format('d M Y'),
        'Dicetak' => $generatedAt->format('d M Y H:i'),
    ],
])

<div class="section">
    <div class="section-title">01 &nbsp; Inspection Details</div>

    <table class="kv">
        <tr>
            <td class="k">Operating Unit</td>
            <td class="v">{{ $inspection->company->name ?? '-' }}</td>
            <td class="k">Inspector</td>
            <td class="v">{{ $inspection->inspector->name ?? '-' }}</td>
        </tr>
        <tr>
            <td class="k">Project</td>
            <td class="v">{{ $inspection->project->name ?? '-' }}</td>
            <td class="k">Location</td>
            <td class="v">{{ $inspection->location ?: '-' }}</td>
        </tr>
        <tr>
            <td class="k">Inspection Type</td>
            <td class="v">{{ $typeLabel ?: '-' }}</td>
            <td class="k">Checklist Items</td>
            <td class="v">{{ count($items) }}</td>
        </tr>
    </table>
</div>

<div class="section">
    <div class="section-title">02 &nbsp; Checklist Findings</div>

    @if ($items === [])
        <p class="note">
            Inspeksi ini tidak memuat butir checklist. Dokumen sengaja diterbitkan tanpa
            tabel pemeriksaan agar tidak terbaca sebagai inspeksi yang sudah dinilai.
        </p>
    @else
        <table class="data">
            {{-- A real thead: Dompdf repeats it on every page of a long
                 checklist, which is the whole reason it is declared. --}}
            <thead>
                <tr>
                    <th class="ctr" style="width: 26px;">No</th>
                    <th>Inspection Item</th>
                    <th class="ctr" style="width: 62px;">Result</th>
                    <th style="width: 34%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $index => $raw)
                    @php $line = $readItem($raw); @endphp
                    <tr>
                        <td class="ctr">{{ $index + 1 }}</td>
                        <td>{{ $line['label'] ?: '-' }}</td>
                        <td class="ctr">
                            <span class="verdict {{ $verdictClass($line['verdict']) }}">{{ $verdictLabel($line['verdict']) }}</span>
                        </td>
                        <td>{{ $line['note'] ?: '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>

<div class="result-banner {{ $resultClass }} avoid-break">
    <div class="result-label">Overall Result</div>
    <div class="result-value">
        {{ $inspection->overall_result ? strtoupper(str_replace('_', ' ', $inspection->overall_result)) : 'BELUM DINILAI' }}
    </div>
    <div class="result-note">
        @if ($inspection->overall_result)
            {{ $notOk }} dari {{ count($items) }} butir dinilai tidak sesuai.
        @else
            Hasil keseluruhan belum tercatat pada inspeksi ini.
        @endif
    </div>
</div>

@if ($inspection->notes)
    <div class="section">
        <div class="section-title">03 &nbsp; Inspector Notes</div>
        <div class="prose">{{ $inspection->notes }}</div>
    </div>
@endif

@if ($actions->isNotEmpty())
    <div class="section">
        <div class="section-title">04 &nbsp; Corrective Actions</div>

        <table class="data">
            <thead>
                <tr>
                    <th class="ctr" style="width: 26px;">No</th>
                    <th>Action</th>
                    <th style="width: 110px;">Assignee</th>
                    <th class="ctr" style="width: 70px;">Due Date</th>
                    <th class="ctr" style="width: 62px;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($actions as $index => $action)
                    <tr>
                        <td class="ctr">{{ $index + 1 }}</td>
                        <td>{{ $action->action }}</td>
                        <td>{{ $action->assignee->name ?? 'Belum ditugaskan' }}</td>
                        <td class="ctr">{{ optional($action->due_date)->format('d M Y') ?: '-' }}</td>
                        <td class="ctr">{{ strtoupper(str_replace('_', ' ', (string) $action->status)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

{{-- The inspector's own box carries a name because the record holds one.
     The reviewing box is left empty on purpose: no approver is invented. --}}
@include('pdf.partials.signatures', [
    'signatures' => [
        ['role' => 'Inspected By', 'name' => $inspection->inspector->name ?? null, 'position' => 'HSE Inspector'],
        ['role' => 'Reviewed By', 'name' => null, 'position' => 'HSE Supervisor'],
        ['role' => 'Acknowledged By', 'name' => null, 'position' => 'Site Management'],
    ],
])

@include('pdf.partials.footer', [
    'identity' => $identity,
    'documentNumber' => $inspection->inspection_number,
    'controlNote' => 'Dokumen terkendali · HSE Inspection Record',
])

</body>
</html>
